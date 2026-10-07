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
 *                  ajax:my_action / admin-post:my_action  an AJAX / admin-post.php handler
 *                  rest:/contact-form-7/v1/...            a REST route
 *                  cron, cli, admin, wp-login, front      anything else, by request type
 *   source_page  the post/page the visitor was on (0 if none or unknown)
 *
 * Form plugins are recognized through their own send hooks, which say
 * exactly which form (and, for Forminator, which notification) is sending.
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

	/** @var array Form models by id, for label lookups. */
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
		add_action( 'forminator_custom_form_mail_after_send_mail', array( $this, 'forminator_end' ) );
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

	public function forminator_end() {
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
	 * Settings-page group a source's page picker belongs to: one per
	 * Forminator form (shared by its notifications), otherwise the source.
	 */
	public static function group_key( $source ) {
		return preg_match( '/^(forminator:\d+)(:|$)/', $source, $m ) ? $m[1] : $source;
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

	/** Whether Forminator is active, so its forms can be listed. */
	public function has_forminator() {
		return class_exists( 'Forminator_API' );
	}

	/**
	 * Forminator forms with their notifications, for the settings page.
	 *
	 * @return array[] { id, name, notifications: array[] { source, label, recipients } }
	 */
	public function forminator_forms() {
		if ( ! $this->has_forminator() ) {
			return array();
		}
		$models = Forminator_API::get_forms( null, 1, 200 );
		if ( ! is_array( $models ) ) {
			return array();
		}

		$out = array();
		foreach ( $models as $form ) {
			if ( empty( $form->id ) ) {
				continue;
			}
			$this->forms[ (int) $form->id ] = $form;

			$notifications = array();
			foreach ( ( isset( $form->notifications ) && is_array( $form->notifications ) ? $form->notifications : array() ) as $n ) {
				if ( empty( $n['slug'] ) ) {
					continue;
				}
				$recipients = ( isset( $n['email-recipients'] ) && 'default' !== $n['email-recipients'] )
					? 'conditional recipients'
					: ( isset( $n['recipients'] ) ? (string) $n['recipients'] : '' );

				$notifications[] = array(
					'source'     => self::clean( 'forminator:' . (int) $form->id . ':' . $n['slug'] ),
					'label'      => ! empty( $n['label'] ) ? $n['label'] : $n['slug'],
					'recipients' => $recipients,
				);
			}

			$out[] = array(
				'id'            => (int) $form->id,
				'name'          => $this->forminator_form_name( $form ),
				'notifications' => $notifications,
			);
		}
		return $out;
	}

	/**
	 * Pages a Forminator form can be picked on: where its shortcode or block
	 * appears in post content, plus any page a logged submission came from
	 * (which also covers page builders that keep content elsewhere).
	 *
	 * @return array page id => title
	 */
	public function forminator_pages( $form_id ) {
		global $wpdb;
		$form_id = (int) $form_id;

		$likes = array(
			'%' . $wpdb->esc_like( '[forminator_form id="' . $form_id . '"' ) . '%',
			'%' . $wpdb->esc_like( "[forminator_form id='" . $form_id . "'" ) . '%',
			'%' . $wpdb->esc_like( '"module_id":"' . $form_id . '"' ) . '%',
		);
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','private','draft')
				   AND post_type NOT IN ('revision','nav_menu_item')
				   AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
				 LIMIT 50",
				$likes[0],
				$likes[1],
				$likes[2]
			)
		);

		$logged = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_page FROM ' . WPEL_Mailgun_Monitor::table() . '
				 WHERE source_page > 0 AND ( source = %s OR source LIKE %s )
				 LIMIT 50',
				'forminator:' . $form_id,
				$wpdb->esc_like( 'forminator:' . $form_id . ':' ) . '%'
			)
		);

		return $this->titles( array_merge( $ids, $logged ) );
	}

	/**
	 * Non-Forminator sources seen in the log, with the pages each came from.
	 *
	 * @return array source => array( page id => title )
	 */
	public function logged_sources() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT source, source_page FROM " . WPEL_Mailgun_Monitor::table() . "
			 WHERE source IS NOT NULL AND source <> '' AND source NOT LIKE 'forminator:%'
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
		if ( ! array_key_exists( $id, $this->forms ) ) {
			$form               = $this->has_forminator() ? Forminator_API::get_form( $id ) : null;
			$this->forms[ $id ] = ( $form && ! is_wp_error( $form ) ) ? $form : null;
		}
		return $this->forms[ $id ];
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
