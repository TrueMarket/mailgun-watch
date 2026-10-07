<?php
/**
 * Works out where each email came from, so the log can show it and the
 * unopened-email alert can be limited to the sources an admin cares about.
 *
 * wp_mail() itself has no idea what triggered it, so this is recorded at
 * send time (see WPEL_Mailgun_Monitor::capture_outgoing()) as two values:
 *
 *   source       what sent it, e.g.
 *                  forminator:123:notification-1234-4567  one notification of a Forminator form
 *                  html-forms:45:email-1                  the 1st "Send Email" action of an HTML Forms form
 *                  ajax:my_action / admin-post:my_action  an AJAX / admin-post.php handler
 *                  rest:/contact-form-7/v1/...            a REST route
 *                  cron, cli, admin, wp-login, front      anything else, by request type
 *   source_page  the post/page the visitor was on (0 if none or unknown)
 *
 * Form plugins (Forminator, HTML Forms) are recognized through their own
 * send hooks, which say exactly which form, and which of its emails, is
 * sending.
 * Everything else falls back to the request type, with the page taken from
 * the referer for AJAX/REST/admin-post requests, since form submissions are
 * usually posted somewhere other than the page the form is on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Sources {

	/** @var WPEL_Sources */
	private static $instance;

	/**
	 * Set by a form plugin's send hooks while that plugin is sending:
	 * array( 'form' => 'forminator:123', 'source' => ..., 'page' => int ).
	 *
	 * @var array|null
	 */
	private $context = null;

	/** @var array Form plugin models by group key (e.g. 'forminator:83'), for label lookups. */
	private $forms = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Forminator. before/after_send_mail wrap every email a submission
		// sends; the message filter runs once per notification just before
		// it's sent, and is the only hook that's given the notification itself
		// (6th argument, Forminator 1.57+).
		add_action( 'forminator_custom_form_mail_before_send_mail', array( $this, 'forminator_begin' ), 10, 3 );
		add_filter( 'forminator_custom_form_mail_admin_message', array( $this, 'forminator_notification' ), PHP_INT_MAX, 6 );
		add_action( 'forminator_custom_form_mail_after_send_mail', array( $this, 'form_end' ) );

		// HTML Forms. hf_process_form runs once per submission before any of
		// the form's actions; each "Send Email" action then runs on
		// hf_process_form_action_email. Those actions have no id, so they're
		// numbered by position (email-1, email-2, ...) — the same order the
		// settings page lists them in. Any other action that sends mail is
		// recorded at the form level.
		add_action( 'hf_process_form', array( $this, 'html_forms_begin' ), 1, 2 );
		add_action( 'hf_process_form_action_email', array( $this, 'html_forms_email' ), 1 );
		add_action( 'hf_process_form_action_email', array( $this, 'html_forms_email_done' ), PHP_INT_MAX );
		add_action( 'hf_form_success', array( $this, 'form_end' ), PHP_INT_MAX );
	}

	/* -------------------------------------------------------------------- */
	/* Send-time detection                                                    */
	/* -------------------------------------------------------------------- */

	/**
	 * Source of the wp_mail() call happening right now.
	 *
	 * @return array { source: string, page: int }
	 */
	public function current() {
		if ( $this->context ) {
			return array(
				'source' => $this->context['source'],
				'page'   => $this->context['page'],
			);
		}

		$from_referer = false;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$source = 'cli';
		} elseif ( wp_doing_cron() ) {
			$source = 'cron';
		} elseif ( wp_doing_ajax() ) {
			$source       = 'ajax:' . $this->request_action();
			$from_referer = true;
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route        = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			$source       = 'rest:' . $route;
			$from_referer = true;
		} elseif ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			$source       = 'admin-post:' . $this->request_action();
			$from_referer = true;
		} elseif ( is_admin() ) {
			$source = 'admin';
		} elseif ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$source = 'wp-login';
		} else {
			$source = 'front';
		}

		if ( $from_referer ) {
			$page = $this->page_from_url( (string) wp_get_raw_referer() );
		} elseif ( 'front' === $source && did_action( 'wp' ) && is_singular() ) {
			$page = (int) get_queried_object_id();
		} else {
			$page = 0;
		}

		return array(
			'source' => self::clean( $source ),
			'page'   => $page,
		);
	}

	/** @param Forminator_CForm_Front_Mail $mail Unused. */
	public function forminator_begin( $mail, $custom_form, $data ) {
		$form = 'forminator:' . (int) ( isset( $custom_form->id ) ? $custom_form->id : 0 );
		$data = is_array( $data ) ? $data : array();

		$page = ! empty( $data['page_id'] ) ? absint( $data['page_id'] ) : 0;
		if ( ! $page && ! empty( $data['current_url'] ) ) {
			$page = $this->page_from_url( (string) $data['current_url'] );
		}

		$this->context = array(
			'form'   => $form,
			'source' => $form, // narrowed to the notification in forminator_notification()
			'page'   => $page,
		);
	}

	/**
	 * Notes which notification is about to be sent; passes the message
	 * through untouched. On Forminator older than 1.57 there's no
	 * $notification, and the source stays at the form level.
	 */
	public function forminator_notification( $message, $custom_form = null, $data = null, $entry = null, $mail = null, $notification = null ) {
		if ( $this->context ) {
			$this->context['source'] = ( is_array( $notification ) && ! empty( $notification['slug'] ) )
				? self::clean( $this->context['form'] . ':' . $notification['slug'] )
				: $this->context['form'];
		}
		return $message;
	}

	/** @param HTML_Forms\Form $form @param HTML_Forms\Submission $submission */
	public function html_forms_begin( $form, $submission ) {
		$form_key = 'html-forms:' . (int) ( isset( $form->ID ) ? $form->ID : 0 );
		$referer  = ! empty( $submission->referer_url ) ? (string) $submission->referer_url : (string) wp_get_raw_referer();

		$this->context = array(
			'form'   => $form_key,
			'source' => $form_key,
			'page'   => $this->page_from_url( $referer ),
			'emails' => 0,
		);
	}

	public function html_forms_email() {
		if ( $this->context ) {
			$this->context['emails']++;
			$this->context['source'] = $this->context['form'] . ':email-' . $this->context['emails'];
		}
	}

	public function html_forms_email_done() {
		if ( $this->context ) {
			$this->context['source'] = $this->context['form'];
		}
	}

	/** A form plugin has finished sending for this submission. */
	public function form_end() {
		$this->context = null;
	}

	/** The request's 'action' parameter, as AJAX and admin-post.php route on it. */
	private function request_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to label the log row.
		return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	}

	/** Post id for a URL on this site, including a static front page; 0 if none. */
	private function page_from_url( $url ) {
		if ( '' === $url ) {
			return 0;
		}
		$id = (int) url_to_postid( $url );
		if ( ! $id && 'page' === get_option( 'show_on_front' ) ) {
			$path = strtok( $url, '?#' );
			if ( untrailingslashit( (string) $path ) === untrailingslashit( home_url() ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
		}
		return $id;
	}

	/** Limits a source to the characters and length the log column holds. */
	public static function clean( $source ) {
		return substr( preg_replace( '/[^A-Za-z0-9_:\/.\-]/', '', (string) $source ), 0, 191 );
	}

	/* -------------------------------------------------------------------- */
	/* Unopened-alert watch list                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Sources the unopened alert is limited to, as source => page id (0 =
	 * any page), or null when it covers every delivered email.
	 *
	 * @return array|null
	 */
	public static function watched() {
		$o = get_option( WPEL_OPTION, array() );
		if ( empty( $o['unopened_scope'] ) || 'selected' !== $o['unopened_scope'] ) {
			return null;
		}
		return ! empty( $o['unopened_watch'] ) && is_array( $o['unopened_watch'] ) ? $o['unopened_watch'] : array();
	}

	/**
	 * Settings-page group a source's page picker belongs to: one per form
	 * (shared by its notifications/email actions), otherwise the source.
	 */
	public static function group_key( $source ) {
		return preg_match( '/^((?:forminator|html-forms):\d+)(:|$)/', $source, $m ) ? $m[1] : $source;
	}

	/* -------------------------------------------------------------------- */
	/* Labels and listings for the admin screens                             */
	/* -------------------------------------------------------------------- */

	/** Human-readable name for a source, e.g. "Contact Us (Forminator) — Admin Email". */
	public function label( $source ) {
		$source = (string) $source;
		if ( '' === $source ) {
			return '';
		}

		if ( preg_match( '/^forminator:(\d+)(?::(.+))?$/', $source, $m ) ) {
			$form  = $this->forminator_form( (int) $m[1] );
			$label = ( $form ? $this->forminator_form_name( $form ) : 'Form #' . $m[1] ) . ' (Forminator)';
			if ( ! empty( $m[2] ) ) {
				$notification = $form ? $this->forminator_notification_by_slug( $form, $m[2] ) : null;
				$label       .= ' — ' . ( $notification && ! empty( $notification['label'] ) ? $notification['label'] : $m[2] );
			}
			return $label;
		}

		if ( preg_match( '/^html-forms:(\d+)(?::email-(\d+))?$/', $source, $m ) ) {
			$form  = $this->html_forms_form( (int) $m[1] );
			$label = ( $form && '' !== $form->title ? $form->title : 'Form #' . $m[1] ) . ' (HTML Forms)';
			if ( ! empty( $m[2] ) ) {
				$label .= ' — Email #' . $m[2];
			}
			return $label;
		}

		$fixed = array(
			'cron'     => 'Scheduled task (WP-Cron)',
			'cli'      => 'WP-CLI',
			'admin'    => 'WordPress admin',
			'wp-login' => 'Login / password reset',
			'front'    => 'Front-end page',
		);
		if ( isset( $fixed[ $source ] ) ) {
			return $fixed[ $source ];
		}

		list( $type, $detail ) = array_pad( explode( ':', $source, 2 ), 2, '' );
		$types = array(
			'ajax'       => 'AJAX',
			'admin-post' => 'admin-post',
			'rest'       => 'REST API',
		);
		if ( isset( $types[ $type ] ) ) {
			return $types[ $type ] . ': ' . ( '' !== $detail ? $detail : '(no action)' );
		}
		return $source;
	}

	/** Title of a source page, falling back to its id. */
	public static function page_title( $page_id ) {
		$title = get_the_title( $page_id );
		return '' !== $title ? $title : '#' . (int) $page_id;
	}

	/** Form plugins this class knows, by source prefix => display name. */
	const FORM_PLUGINS = array(
		'forminator' => 'Forminator',
		'html-forms' => 'HTML Forms',
	);

	/** Whether a form plugin (a FORM_PLUGINS prefix) is active, so its forms can be listed. */
	public function plugin_active( $prefix ) {
		if ( 'forminator' === $prefix ) {
			return class_exists( 'Forminator_API' );
		}
		if ( 'html-forms' === $prefix ) {
			return function_exists( 'hf_get_forms' );
		}
		return false;
	}

	/**
	 * Every active form plugin's forms and the emails each one sends, for
	 * the settings page.
	 *
	 * @return array prefix => array( name, forms: array[] { group, id, name, emails: array[] { source, label, recipients } } )
	 */
	public function form_plugins() {
		$out = array();
		foreach ( self::FORM_PLUGINS as $prefix => $name ) {
			if ( $this->plugin_active( $prefix ) ) {
				$out[ $prefix ] = array(
					'name'  => $name,
					'forms' => 'forminator' === $prefix ? $this->forminator_forms() : $this->html_forms_forms(),
				);
			}
		}
		return $out;
	}

	/** @return array[] Forminator forms, one email per notification. */
	private function forminator_forms() {
		$models = Forminator_API::get_forms( null, 1, 200 );
		if ( ! is_array( $models ) ) {
			return array();
		}

		$out = array();
		foreach ( $models as $form ) {
			if ( empty( $form->id ) ) {
				continue;
			}
			$group                 = 'forminator:' . (int) $form->id;
			$this->forms[ $group ] = $form;

			$emails = array();
			foreach ( ( isset( $form->notifications ) && is_array( $form->notifications ) ? $form->notifications : array() ) as $n ) {
				if ( empty( $n['slug'] ) ) {
					continue;
				}
				$recipients = ( isset( $n['email-recipients'] ) && 'default' !== $n['email-recipients'] )
					? 'conditional recipients'
					: ( isset( $n['recipients'] ) ? (string) $n['recipients'] : '' );

				$emails[] = array(
					'source'     => self::clean( $group . ':' . $n['slug'] ),
					'label'      => ! empty( $n['label'] ) ? $n['label'] : $n['slug'],
					'recipients' => $recipients,
				);
			}

			$out[] = array(
				'group'  => $group,
				'id'     => (int) $form->id,
				'name'   => $this->forminator_form_name( $form ),
				'emails' => $emails,
			);
		}
		return $out;
	}

	/**
	 * @return array[] HTML Forms forms, one email per "Send Email" action,
	 *                 numbered the same way as html_forms_email() does.
	 */
	private function html_forms_forms() {
		$out = array();
		foreach ( hf_get_forms() as $form ) {
			$group                 = 'html-forms:' . (int) $form->ID;
			$this->forms[ $group ] = $form;

			$emails = array();
			$n      = 0;
			foreach ( ( isset( $form->settings['actions'] ) && is_array( $form->settings['actions'] ) ? $form->settings['actions'] : array() ) as $action ) {
				if ( ! isset( $action['type'] ) || 'email' !== $action['type'] ) {
					continue;
				}
				$n++;
				$emails[] = array(
					'source'     => $group . ':email-' . $n,
					'label'      => 'Email #' . $n,
					'recipients' => isset( $action['to'] ) ? (string) $action['to'] : '',
				);
			}

			$out[] = array(
				'group'  => $group,
				'id'     => (int) $form->ID,
				'name'   => '' !== $form->title ? $form->title : 'Form #' . $form->ID,
				'emails' => $emails,
			);
		}
		return $out;
	}

	/**
	 * Pages a form can be picked on: where its shortcode or block appears in
	 * post content, plus any page a logged submission came from (which also
	 * covers page builders that keep content elsewhere).
	 *
	 * @param string $group e.g. 'forminator:83', 'html-forms:12'.
	 * @return array page id => title
	 */
	public function form_pages( $group ) {
		global $wpdb;
		list( $prefix, $form_id ) = array_pad( explode( ':', $group, 2 ), 2, 0 );
		$form_id                  = (int) $form_id;

		$needles = array();
		if ( 'forminator' === $prefix ) {
			$needles = array(
				'[forminator_form id="' . $form_id . '"',
				"[forminator_form id='" . $form_id . "'",
				'"module_id":"' . $form_id . '"',
			);
		} elseif ( 'html-forms' === $prefix ) {
			$needles = array(
				'[hf_form id="' . $form_id . '"',
				"[hf_form id='" . $form_id . "'",
			);
			$form = $this->html_forms_form( $form_id );
			if ( $form && '' !== $form->slug ) {
				$needles[] = '[hf_form slug="' . $form->slug . '"';
				$needles[] = "[hf_form slug='" . $form->slug . "'";
				$needles[] = '<!-- wp:html-forms/form {"slug":"' . $form->slug . '"';
			}
		}

		$ids = array();
		if ( $needles ) {
			$likes = array();
			foreach ( $needles as $needle ) {
				$likes[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' );
			}
			$ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','private','draft')
				   AND post_type NOT IN ('revision','nav_menu_item')
				   AND ( " . implode( ' OR ', $likes ) . ' )
				 LIMIT 50'
			);
		}

		$logged = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_page FROM ' . WPEL_Mailgun_Monitor::table() . '
				 WHERE source_page > 0 AND ( source = %s OR source LIKE %s )
				 LIMIT 50',
				$group,
				$wpdb->esc_like( $group . ':' ) . '%'
			)
		);

		return $this->titles( array_merge( $ids, $logged ) );
	}

	/**
	 * Sources seen in the log other than those of active form plugins (which
	 * the settings page lists by form instead), with the pages each came from.
	 *
	 * @return array source => array( page id => title )
	 */
	public function logged_sources() {
		global $wpdb;
		$exclude = '';
		foreach ( array_keys( self::FORM_PLUGINS ) as $prefix ) {
			if ( $this->plugin_active( $prefix ) ) {
				$exclude .= $wpdb->prepare( ' AND source NOT LIKE %s', $wpdb->esc_like( $prefix . ':' ) . '%' );
			}
		}
		$rows = $wpdb->get_results(
			"SELECT source, source_page FROM " . WPEL_Mailgun_Monitor::table() . "
			 WHERE source IS NOT NULL AND source <> ''{$exclude}
			 GROUP BY source, source_page
			 ORDER BY source
			 LIMIT 500"
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $out[ $row->source ] ) ) {
				$out[ $row->source ] = array();
			}
			if ( (int) $row->source_page > 0 ) {
				$out[ $row->source ][] = (int) $row->source_page;
			}
		}
		return array_map( array( $this, 'titles' ), $out );
	}

	/** Every source seen in the log, for the Email Log's filter. */
	public function all_logged_sources() {
		global $wpdb;
		return $wpdb->get_col(
			"SELECT DISTINCT source FROM " . WPEL_Mailgun_Monitor::table() . "
			 WHERE source IS NOT NULL AND source <> ''
			 ORDER BY source
			 LIMIT 500"
		);
	}

	/** @return array page id => title, for the given ids (deduplicated, missing posts dropped). */
	private function titles( $ids ) {
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && get_post( $id ) ) {
				$out[ $id ] = self::page_title( $id );
			}
		}
		return $out;
	}

	private function forminator_form( $id ) {
		$group = 'forminator:' . $id;
		if ( ! array_key_exists( $group, $this->forms ) ) {
			$form                  = $this->plugin_active( 'forminator' ) ? Forminator_API::get_form( $id ) : null;
			$this->forms[ $group ] = ( $form && ! is_wp_error( $form ) ) ? $form : null;
		}
		return $this->forms[ $group ];
	}

	private function html_forms_form( $id ) {
		$group = 'html-forms:' . $id;
		if ( ! array_key_exists( $group, $this->forms ) ) {
			$form = null;
			if ( $this->plugin_active( 'html-forms' ) ) {
				try {
					$form = hf_get_form( $id );
				} catch ( Exception $e ) {
					$form = null; // deleted form
				}
			}
			$this->forms[ $group ] = $form;
		}
		return $this->forms[ $group ];
	}

	private function forminator_form_name( $form ) {
		if ( ! empty( $form->settings['formName'] ) ) {
			return $form->settings['formName'];
		}
		if ( ! empty( $form->name ) ) {
			return $form->name;
		}
		return 'Form #' . $form->id;
	}

	private function forminator_notification_by_slug( $form, $slug ) {
		foreach ( ( isset( $form->notifications ) && is_array( $form->notifications ) ? $form->notifications : array() ) as $n ) {
			if ( isset( $n['slug'] ) && self::clean( $n['slug'] ) === $slug ) {
				return $n;
			}
		}
		return null;
	}
}
