<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

class FW_Extension_Learning_Grading extends FW_Extension {

	/**
	 * @var string
	 */
	private $user_role = 'learning-instructor';

	/**
	 * @var string
	 */
	private $user_name = 'Instructor';

	/**
	 * @var array
	 */
	private $user_capabilities = array(
		'read',
		'publish_posts',
		'edit_published_posts',
		'edit_posts',
		'delete_published_posts',
		'delete_posts',
		'upload_files',
	);

	/**
	 * @var FW_Extension_Learning
	 */
	private $learning = null;

	/**
	 * @var FW_Extension_Learning_Quiz
	 */
	private $quiz = null;

	/**
	 * @var FW_Extension_Learning_Student
	 */
	private $student = null;

	/**
	 * @internal
	 */
	public function _init() {
		$this->learning = fw_ext( 'learning' );
		$this->quiz     = fw_ext( 'learning-quiz' );
		$this->student  = fw_ext( 'learning-student' );
		$this->define_role();
		$this->register_role();

		if ( is_admin() ) {
			$this->admin_filters();
			$this->admin_actions();
		} else {
			$this->theme_actions();
		}
	}

	public function _display_admin_page() {
		if ( ! $this->is_learning_grading_page() ) {
			return;
		}

		$quiz_id = ( int ) FW_Request::GET( 'quiz-id' );
		$user_id = ( int ) FW_Request::GET( 'user-id' );

		if ( $this->is_quiz_review_page() ) {
			$this->_quiz_review( $quiz_id, $user_id );
		} elseif ( $this->is_users_listing_page() ) {
			$this->_quiz_users( $quiz_id );
		} elseif ( $this->is_quiz_listing_page() ) {
			$this->_quiz_listing();
		}
	}

	/**
	 * @internal
	 *
	 * @param int $post_id
	 * @param WP_Post $post
	 */
	public function _action_admin_save_quiz_type( $post_id, $post ) {
		if ( $post->post_type != $this->learning->get_lesson_post_type() ) {
			return;
		}

		$quiz = get_post( $post_id );

		if ( empty( $quiz ) ) {
			return;
		}

		$option = fw_get_db_post_option( $post_id, $this->get_name() . '-process-manually' );

		fw_set_db_post_option( $quiz->ID, $this->get_name() . '-process-manually', $option );

		if ( $option ) {
			update_post_meta( $quiz->ID, $this->get_name() . '-process-manually', true );
		} else {
			update_post_meta( $quiz->ID, $this->get_name() . '-process-manually', false );
		}
	}

	/**
	 * @internal
	 */
	public function _action_admin_add_admin_menu() {

		$screen_hook = add_menu_page(
			__( 'Instructor', 'fw' ),
			__( 'Instructor', 'fw' ),
			'publish_posts',
			$this->get_name(),
			array( $this, '_display_admin_page' ),
			'dashicons-businessman',
			8
		);

		add_action( 'load-' . $screen_hook, array( $this, '_action_admin_add_screen_options' ) );
	}

	/**
	 * @internal
	 */
	public function _action_admin_add_screen_options() {

		if ( ! $this->is_users_listing_page() && ! $this->is_quiz_listing_page() ) {
			return;
		}

		$option = 'per_page';

		if ( $this->is_users_listing_page() ) {
			$args = array(
				'label'   => __( 'Users', 'fw' ),
				'default' => 20,
				'option'  => 'learning_grading_users_per_page'
			);
		} else {
			$args = array(
				'label'   => __( 'Quiz tests', 'fw' ),
				'default' => 20,
				'option'  => 'learning_grading_quiz_per_page'
			);
		}

		add_screen_option( $option, $args );
	}

	/**
	 * @internal
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function _filter_admin_set_quiz_options( $options ) {
		$grading_options = array(
			$this->get_name() . '-process-manually' => array(
				'type'         => 'switch',
				'value'        => false,
				'label'        => __( 'Process quiz manually', 'fw' ),
				'desc'         => __( 'The quiz requires to be reviewed by lesson author before grading the student',
					'fw' ),
				'left-choice'  => array(
					'value' => false,
					'label' => __( 'No', 'fw' ),
				),
				'right-choice' => array(
					'value' => true,
					'label' => __( 'Yes', 'fw' ),
				),
			)
		);

		return array_merge( $options, $grading_options );
	}

	/**
	 * @internal
	 *
	 * @param $status
	 * @param $option
	 * @param $value
	 */
	public function _filter_admin_save_screen_option( $status, $option, $value ) {

		if ( $option == 'learning_grading_users_per_page'
		     || $option == 'learning_grading_quiz_per_page'
		) {
			return $value;
		}

		return $status;
	}

	/**
	 * @internal
	 *
	 * @param array $return
	 * @param int $id
	 */
	public function _action_theme_process_quiz( $return, $id ) {
		if ( ! $this->quiz->has_quiz( $id ) ) {
			return;
		}

		if ( $this->requires_instructor( $id ) ) {
			$return['status'] = 'pending';
		} else {
			if ( $return['minimum-pass-mark'] <= $return['accumulated'] ) {
				$return['status'] = 'passed';
			} else {
				$return['status'] = 'failed';
			}
		}

		$return['time'] = time();

		$data = array(
			'quiz' => new FW_Learning_Grading_Quiz_Review( $return )
		);

		$this->student->add_lesson_data( $id, $data );
		fw_update_user_meta( $this->student->id(), 'learning-grading-quiz-status-' . $id, $return['status'] );
	}

	/**
	 * Check if the quiz requires instructor to be processed
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public function requires_instructor( $id ) {
		if ( empty( $id ) || ! $this->quiz->has_quiz( $id ) ) {
			return false;
		}

		if ( fw_get_db_post_option( $id, $this->get_name() . '-process-manually' ) == true ) {
			return true;
		}

		return false;
	}

	public function get_link() {
		return menu_page_url( $this->get_name() );
	}

	public function is_learning_grading_page() {
		if ( fw_current_screen_match( array(
			'only' => array(
				'id'   => 'toplevel_page_' . $this->get_name(),
				'base' => 'toplevel_page_' . $this->get_name(),
			)
		) ) ) {
			return true;
		}

		return false;
	}

	public function is_quiz_listing_page() {
		if ( ! $this->is_learning_grading_page()
		     || $this->is_quiz_review_page()
		     || $this->is_users_listing_page()
		) {
			return false;
		}

		return true;
	}

	public function is_users_listing_page() {

		if ( ! $this->is_learning_grading_page() ) {
			return false;
		}

		$page = FW_Request::GET( 'sub-page' );

		if ( $page == 'users' ) {
			return true;
		}

		return false;
	}

	public function is_quiz_review_page() {

		if ( ! $this->is_learning_grading_page() ) {
			return false;
		}

		$page = FW_Request::GET( 'sub-page' );

		if ( $page == 'review' ) {
			return true;
		}

		return false;
	}

	private function define_role() {
		$name         = $this->get_config( 'user-name' );
		$capabilities = $this->get_config( 'user-capabilities' );

		if ( ! empty( $name ) && is_string( $name ) ) {
			$this->user_name = $name;
		}
		if ( ! empty( $capabilities ) && is_array( $capabilities ) ) {
			$this->user_capabilities = array_unique( array_merge( $this->user_capabilities, $capabilities ) );
		}
	}

	private function register_role() {
		add_role( $this->user_role, $this->user_name, $this->user_capabilities );

		$role = get_role( $this->user_role );

		foreach ( $this->user_capabilities as $cap ) {
			$role->add_cap( $cap );
		}
	}

	private function admin_actions() {
		add_action( 'fw_save_post_options', array( $this, '_action_admin_save_quiz_type' ), 10, 2 );
		add_action( 'admin_menu', array( $this, '_action_admin_add_admin_menu' ) );
	}

	private function admin_filters() {
		add_filter( 'fw_ext_learning_quiz_settings', array( $this, '_filter_admin_set_quiz_options' ) );
		add_filter( 'set-screen-option', array( $this, '_filter_admin_save_screen_option' ), 10, 3 );
	}

	private function theme_actions() {
		add_action( 'fw_ext_learning_quiz_form_process', array( $this, '_action_theme_process_quiz' ), 2, 10 );
	}

	/**
	 * If output already started, we cannot set the redirect header, do redirect from js
	 */
	private function js_redirect() {
		echo
			'<script type="text/javascript">' .
			'window.location.replace("' . esc_js( $this->get_link() ) . '");' .
			'</script>';
	}

	private function _quiz_listing() {
		$user     = get_current_user_id();
		$screen   = get_current_screen();
		$option   = $screen->get_option( 'per_page', 'option' );
		$per_page = ( int ) get_user_meta( $user, $option, true );

		echo fw_render_view(
			$this->get_declared_path() . '/views/quiz-listing.php',
			array(
				'number' => ( $per_page > 0 ) ? $per_page : 20,
			)
		);
	}

	private function _quiz_users( $id ) {

		$user     = get_current_user_id();
		$screen   = get_current_screen();
		$option   = $screen->get_option( 'per_page', 'option' );
		$per_page = ( int ) get_user_meta( $user, $option, true );

		if ( ! $this->student->is_author( $id ) ) {
			$id = 0;
		}

		echo fw_render_view(
			$this->get_declared_path() . '/views/users-listing.php',
			array(
				'id'     => $id,
				'number' => ( $per_page > 0 ) ? $per_page : 20,
			)
		);
	}

	private function _quiz_review( $quiz_id, $user_id ) {
		$user = new FW_Learning_Student( $user_id );

		if (
			! $user->id()
			|| ! $this->quiz->has_quiz( $quiz_id )
			|| $user->get_quiz_status( $quiz_id ) != 'pending'
			|| ( ! $user->is_studying( $quiz_id ) && ! $user->has_passed( $quiz_id ) )
		) {
			FW_Flash_Messages::add( $this->get_name(), __( 'Unable to review the quiz', 'fw' ), 'error' );
			$this->js_redirect();

			return;
		}

		echo fw_render_view(
			$this->get_declared_path() . '/views/quiz-review.php',
			array(
				'quiz' => $quiz_id,
				'user' => $user_id
			)
		);
	}
}