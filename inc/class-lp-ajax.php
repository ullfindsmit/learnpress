<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'LP_AJAX' ) ) {
	/**
	 * Class LP_AJAX
	 */
	class LP_AJAX {
		/**
		 * Init common ajax events
		 */
		public static function init() {
			/*$ajaxEvents = array(
				'load_quiz_question'  => true,
				'load_prev_question'  => false,
				'load_next_question'  => false,
				'finish_quiz'         => true,
				'retake_quiz'         => true, // anonymous user can retake quiz
				'take_free_course'    => false,
				'load_lesson_content' => false,
				'load_next_lesson'    => false,
				'load_prev_lesson'    => false,
				'finish_course'       => false,
				'not_going'           => false,
				'take_course'         => true,
				'start_quiz'          => true,
				'fetch_question'      => true,
				'upload-user-avatar'  => false,
				'check-user-email'    => true
			);

			foreach ( $ajaxEvents as $ajax_event => $nopriv ) {
				$ajax_func = preg_replace( '/-/', '_', $ajax_event );
				add_action( 'wp_ajax_learnpress_' . $ajax_event, array( __CLASS__, $ajax_func ) );

				if ( $nopriv ) {
					add_action( 'wp_ajax_nopriv_learnpress_' . $ajax_event, array( __CLASS__, $ajax_func ) );
				}
			}*/
			/**
			 * action-name
			 *      :nopriv => Allows calling AJAX with user is not logged in
			 *      :nonce  => Requires checking nonce with value of request param action-name-nonce before doing AJAX
			 */
			$ajaxEvents = array(
				'checkout-user-email-exists:nopriv',
				'recover-order',
				'request-become-a-teacher:nonce',
				'upload-user-avatar',
				'checkout:nopriv',
				'complete-lesson',
				'finish-course',
				'retake-course'
			);

			$ajaxEvents = apply_filters( 'learn-press/ajax/events', $ajaxEvents );

			foreach ( $ajaxEvents as $action => $callback ) {

				if ( is_numeric( $action ) ) {
					$action = $callback;
				}

				$actions = LP_Request::parse_action( $action );
				$method  = $actions['action'];

				if ( ! is_callable( $callback ) ) {
					$method   = preg_replace( '/-/', '_', $method );
					$callback = array( __CLASS__, $method );
				}

				LP_Request::register_ajax( $action, $callback );
			}

			add_action( 'wp_ajax_learnpress_upload-user-avatar', array( __CLASS__, 'upload_user_avatar' ) );

			//LP_Request::register_ajax( 'checkout-user-email-exists', array( __CLASS__, 'checkout_user_email_exists' ) );
			//LP_Request::register_ajax( 'recover-order', array( __CLASS__, 'recover_order' ) );
		}

		public static function checkout() {
			LP()->checkout()->process_checkout_handler();
		}

		public static function request_become_a_teacher() {
			LP_Forms_Handler::process_become_teacher();
		}

		public static function recover_order() {
			if ( ! LP_Request::verify_nonce( 'recover-order' ) ) {
				return;
			}

			$factory   = LP_Factory::get_order_factory();
			$user_id   = get_current_user_id();
			$order_key = LP_Request::get_string( 'order-key' );
			$order     = $factory->recover( $order_key, $user_id );
			$result    = array( 'result' => 'success' );

			if ( is_wp_error( $order ) ) {
				$result['message'] = $order->get_error_message();
				$result['result']  = 'error';
			} else {
				$result['message']  = sprintf( __( 'The order %s has been recovered successful.', 'learnpress' ), $order_key );
				$result['redirect'] = $order->get_view_order_url();
			}

			$result = apply_filters( 'learn-press/order/recover-result', $result, $order_key, $user_id );

			learn_press_maybe_send_json( $result );

			if ( ! empty( $result['message'] ) ) {
				learn_press_add_message( $result['message'] );
			}

			if ( ! empty( $result['redirect'] ) ) {
				wp_redirect( $result['redirect'] );
				exit();
			}
		}

		public static function checkout_user_email_exists() {

			$email    = LP_Request::get_email( 'email' );
			$response = array(
				'exists' => 0
			);

			if ( $user = get_user_by( 'email', $email ) ) {
				$response['exists'] = $email;
			}

			if ( $waiting_payment = LP()->checkout()->get_user_waiting_payment() ) {
				$response['waiting_payment'] = $waiting_payment;
			}

			learn_press_maybe_send_json( $response );
		}

		public static function upload_user_avatar() {
			$file       = $_FILES['lp-upload-avatar'];
			$upload_dir = learn_press_user_profile_picture_upload_dir();

			add_filter( 'upload_dir', array( __CLASS__, '_user_avatar_upload_dir' ), 10000 );

			$result = wp_handle_upload( $file,
				array(
					'test_form' => false
				)
			);

			remove_filter( 'upload_dir', array( __CLASS__, '_user_avatar_upload_dir' ), 10000 );
			if ( is_array( $result ) ) {
				$result['name'] = $upload_dir['subdir'] . '/' . basename( $result['file'] );
				unset( $result['file'] );
			} else {
				$result = array(
					'error' => __( 'Upload profile avatar error.', 'learnpress' )
				);
			}
			learn_press_send_json( $result );
		}

		public static function _user_avatar_upload_dir( $dir ) {
			$dir = learn_press_user_profile_picture_upload_dir();

			return $dir;
		}

		/**
		 * Request finish course
		 */
		public static function finish_course() {
			$nonce     = LP_Request::get_string( 'finish-course-nonce' );
			$course_id = LP_Request::get_int( 'course-id' );
			$course    = LP_Course::get_course( $course_id );
			$user      = learn_press_get_current_user();

			$nonce_action = sprintf( 'finish-course-%d-%d', $course_id, $user->get_id() );
			if ( ! $user->get_id() || ! $course || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				wp_die( __( 'Access denied!', 'learnpress' ) );
			}
			//LP_Debug::startTransaction();
			$finished = $user->finish_course( $course_id );
			//LP_Debug::rollbackTransaction();
			$response = array(
				'redirect' => get_the_permalink( $course_id )
			);

			if ( $finished ) {
				learn_press_add_message( sprintf( __( 'You have finished this course "%s"', 'learnpress' ), $course->get_title() ) );
				$response['result'] = 'success';
			} else {
				learn_press_add_message( __( 'Error! You cannot finish this course. Please contact your administrator for more information.', 'learnpress' ) );
				$response['result'] = 'error';
			}

			learn_press_maybe_send_json( $response );

			if ( ! empty( $response['redirect'] ) ) {
				wp_redirect( $response['redirect'] );
				exit();
			}
		}

		/**
		 * Complete lesson
		 */
		public static function complete_lesson() {
			$nonce     = LP_Request::get_string( 'complete-lesson-nonce' );
			$item_id   = LP_Request::get_int( 'id' );
			$course_id = LP_Request::get_int( 'course_id' );

			$post     = get_post( $item_id );
			$user     = learn_press_get_current_user();
			$course   = LP_Course::get_course( $course_id );
			$response = array(
				'result'   => 'success',
				'redirect' => $course->get_item_link( $item_id )
			);

			$item         = $course->get_item( $item_id );
			$nonce_action = $item->get_nonce_action( 'complete', $course_id, $user->get_id() );
			try {
				// security check
				if ( ! $post || ( $post && ! wp_verify_nonce( $nonce, $nonce_action ) ) ) {
					throw new Exception( __( 'Error! Invalid lesson or security checked failure', 'learnpress' ), 8000 );
				}

				$result = $user->complete_lesson( $item_id );

				if ( ! is_wp_error( $result ) ) {
					if ( $next = $course->get_next_item() ) {
						$response['redirect'] = $course->get_item_link( $next );
					}

					learn_press_add_message( sprintf( __( 'Congrats! You have completed "%s".', 'learnpress' ), $item->get_title() ) );
				} else {
					learn_press_add_message( $result->get_error_message(), 'error' );
				}

				$response = apply_filters( 'learn-press/user-completed-lesson-result', $response, $item_id, $course_id, $user->get_id() );
			}
			catch ( Exception $ex ) {
				learn_press_add_message( $ex->getMessage(), 'error' );
			}

			if ( learn_press_message_count( 'error' ) ) {
				$response['result'] = 'error';
			}

			learn_press_maybe_send_json( $response );

			if ( ! empty( $response['redirect'] ) ) {
				wp_redirect( $response['redirect'] );
				exit();
			}
		}

		/**
		 * Retake course action
		 */
		public static function retake_course() {
			$security        = LP_Request::get_string( 'retake-course-nonce' );
			$course_id       = LP_Request::get_int( 'course_id' );
			$user            = learn_press_get_current_user();
			$course          = LP_Course::get_course( $course_id );
			$response        = array(
				'result' => 'error'
			);
			$security_action = sprintf( 'retake-course-%d-%d', $course->get_id(), $user->get_id() );
			// security check
			if ( ! wp_verify_nonce( $security, $security_action ) ) {
				learn_press_add_message( __( 'Error! Invalid course or security checked failure', 'learnpress' ), 'error' );
			} else {
				if ( $user->can( 'retake-course', $course_id ) ) {
					if ( ! $result = $user->retake_course( $course_id ) ) {
						learn_press_add_message( __( 'Error!', 'learnpress' ), 'error' );
					} else {
						learn_press_add_message( sprintf( __( 'You have retaken course "%s"', 'learnpress' ), $course->get_title() ) );
						$response['result'] = 'success';
					}
				} else {
					learn_press_add_message( __( 'Error! You can not retake course', 'learnpress' ), 'error' );
				}
			}


			if ( learn_press_message_count( 'error' ) == 0 ) {
				if ( $item = $course->get_item_at( 0 ) ) {
					$redirect = $course->get_item_link( $item );
				} else {
					$redirect = $course->get_permalink();
				}
				$response['redirect'] = apply_filters( 'learn-press/user-retake-course-redirect', $redirect );
				$response             = apply_filters( 'learn-press/user-retaken-course-result', $response, $course_id, $user->get_id() );
			} else {
				$response['redirect'] = $course->get_permalink();
				$response             = apply_filters( 'learn-press/user-retake-course-failed-result', $response, $course_id, $user->get_id() );
			}

			learn_press_maybe_send_json( $response );

			if ( ! empty( $response['redirect'] ) ) {
				wp_redirect( $response['redirect'] );
				exit();
			}
		}
	}
}
// Call class
LP_AJAX::init();

