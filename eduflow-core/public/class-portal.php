<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Portal {
	public static function register() {
		add_shortcode( 'eduflow_student_portal', array( __CLASS__, 'student_shortcode' ) );
		add_shortcode( 'eduflow_teacher_portal', array( __CLASS__, 'teacher_shortcode' ) );
		add_filter( 'query_vars', static function( $vars ){ $vars[] = 'eduflow_portal'; $vars[] = 'batch_id'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'standalone' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_student_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
	}

	public static function assets() {
		if ( get_query_var( 'eduflow_portal' ) || is_singular() ) {
			wp_enqueue_style( 'eduflow-portal', plugins_url( 'assets/portal.css', EDUFLOW_CORE_FILE ), array(), EDUFLOW_CORE_VERSION );
		}
	}

	public static function block_student_admin() {
		if ( wp_doing_ajax() || current_user_can( 'eduflow_manage_institute' ) || current_user_can( 'eduflow_manage_classes' ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( in_array( 'eduflow_student', $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/?eduflow_portal=student' ) );
			exit;
		}
		if ( in_array( 'eduflow_teacher', $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/?eduflow_portal=teacher' ) );
			exit;
		}
	}

	public static function admin_bar( $show ) {
		$user = wp_get_current_user();
		return ( in_array( 'eduflow_student', $user->roles, true ) || in_array( 'eduflow_teacher', $user->roles, true ) ) ? false : $show;
	}

	public static function standalone() {
		$type = sanitize_key( get_query_var( 'eduflow_portal' ) );
		if ( ! in_array( $type, array( 'student', 'teacher', 'classroom' ), true ) ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1">';
		wp_head();
		echo '</head><body class="eduflow-portal-page">';
		if ( 'classroom' === $type ) {
			echo self::classroom_page();
		} else {
			echo 'student' === $type ? self::student_shortcode() : self::teacher_shortcode();
		}
		wp_footer();
		echo '</body></html>';
		exit;
	}

	private static function login_prompt() {
		return '<div class="eduflow-portal-shell"><div class="eduflow-portal-card"><h1>EduFlow Portal</h1><p>Please sign in to continue.</p><a class="eduflow-button" href="' . esc_url( wp_login_url( home_url( '/?eduflow_portal=student' ) ) ) . '">Sign In</a></div></div>';
	}

	public static function student_shortcode() {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		$data = EduFlow_Portal_Service::student_dashboard();
		return self::render( $data, 'Student Portal' );
	}

	public static function teacher_shortcode() {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		$data = EduFlow_Portal_Service::teacher_dashboard();
		return self::render( $data, 'Teacher Portal' );
	}

	private static function render( $data, $heading ) {
		if ( is_wp_error( $data ) ) {
			return '<div class="eduflow-portal-shell"><div class="eduflow-portal-card"><h1>' . esc_html( $heading ) . '</h1><p>' . esc_html( $data->get_error_message() ) . '</p></div></div>';
		}
		$profile = $data['profile'];
		ob_start();
		?>
		<div class="eduflow-portal-shell">
			<header class="eduflow-portal-header">
				<div><span class="eduflow-eyebrow">EduFlow Institute Suite</span><h1><?php echo esc_html( $heading ); ?></h1></div>
				<a class="eduflow-button eduflow-button-muted" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">Sign Out</a>
			</header>
			<section class="eduflow-profile-grid">
				<?php foreach ( self::profile_fields( $data['portal_type'], $profile ) as $label=>$value ) : ?>
				<div class="eduflow-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ?: '—' ); ?></strong></div>
				<?php endforeach; ?>
			</section>
			<?php if ( 'student' === $data['portal_type'] && ! empty( $profile['batch']['classroom_url'] ) ) : ?>
				<section class="eduflow-portal-card"><h2>My Live Classroom</h2><p>Your classroom is tied to your active batch. Use this button whenever your class is live.</p><a class="eduflow-button" href="<?php echo esc_url( $profile['batch']['classroom_url'] ); ?>">Join YTC Live Classroom</a></section>
			<?php endif; ?>
			<section class="eduflow-portal-card"><h2>Today's Classes</h2><?php self::classes( $data['today'] ); ?></section>
			<section class="eduflow-portal-card"><h2>Upcoming Schedule</h2><?php self::classes( $data['upcoming'] ); ?></section>
			<?php if ( 'student' === $data['portal_type'] && ! is_wp_error( $data['attendance'] ) ) : $attendance=$data['attendance']; ?><section class="eduflow-portal-card"><h2>My Attendance</h2><p><strong><?php echo esc_html( null === $attendance['percentage'] ? '—' : $attendance['percentage'].'%' ); ?></strong> &mdash; Present <?php echo esc_html($attendance['counts']['present']); ?>, Absent <?php echo esc_html($attendance['counts']['absent']); ?>, Late <?php echo esc_html($attendance['counts']['late']); ?></p><small><?php echo esc_html($attendance['denominator']); ?></small></section><?php endif; ?>
			<section class="eduflow-portal-card"><h2>Notifications <span class="eduflow-badge"><?php echo esc_html( $data['unread_notifications'] ); ?> unread</span></h2><?php self::notifications( $data['notifications'] ); ?></section>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function profile_fields( $type, $profile ) {
		if ( 'student' === $type ) {
			return array(
				'Student'=>$profile['name'], 'Student ID'=>$profile['canonical_id'], 'Course'=>$profile['course'],
				'Level'=>$profile['level'], 'Class Type'=>$profile['class_type'],
				'Batch'=>$profile['batch']['batch_name'] ?? '', 'Teacher'=>$profile['teacher']['name'] ?? '',
				'Admission Date'=>$profile['admission_date'], 'Access Start'=>$profile['access_start'],
				'Access End'=>$profile['access_end'], 'Access Status'=>$profile['access_status'], 'Fee Status'=>$profile['fee_status'],
			);
		}
		return array(
			'Teacher'=>$profile['name'], 'Teacher ID'=>$profile['canonical_id'], 'Status'=>$profile['teacher_status'],
			'Available Days'=>$profile['available_days'], 'Available From'=>$profile['available_start_time'], 'Available Until'=>$profile['available_end_time'],
		);
	}

	private static function classes( $classes ) {
		if ( ! $classes ) {
			echo '<p>No classes scheduled.</p>';
			return;
		}
		echo '<div class="eduflow-class-list">';
		foreach ( $classes as $class ) {
			$title = $class['demo_session_id'] ?? ( $class['batch_name'] ?? ( $class['student_name'] ?? $class['canonical_id'] ) );
			echo '<article class="eduflow-class-item"><div><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( $class['class_date'] . ' · ' . $class['start_datetime'] . ' – ' . $class['end_datetime'] ) . '</span><span>' . esc_html( ucfirst( $class['class_type'] ) . ' · ' . ucfirst( $class['class_status'] ) ) . '</span></div>';
			if ( ! empty( $class['classroom_url'] ) ) {
				echo '<a class="eduflow-button" href="' . esc_url( $class['classroom_url'] ) . '">Join YTC Live Classroom</a>';
			} elseif ( $class['can_join'] && $class['meet_url'] ) {
				echo '<a class="eduflow-button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $class['meet_url'] ) . '">Join Google Meet</a>';
			} else {
				echo '<span class="eduflow-badge">Classroom unavailable</span>';
			}
			echo '</article>';
		}
		echo '</div>';
	}

	private static function classroom_page() {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		$batch_id = absint( get_query_var( 'batch_id' ) ?: ( $_GET['batch_id'] ?? 0 ) );
		$access = EduFlow_Classroom_Service::access( $batch_id );
		if ( is_wp_error( $access ) ) {
			return '<div class="eduflow-portal-shell"><div class="eduflow-portal-card"><h1>YTC Live Classroom</h1><p>' . esc_html( $access->get_error_message() ) . '</p><a class="eduflow-button" href="' . esc_url( home_url( '/?eduflow_portal=student' ) ) . '">Back to Portal</a></div></div>';
		}
		$domain = esc_js( $access['domain'] );
		$room = esc_js( $access['room_name'] );
		$display = esc_js( $access['display_name'] );
		$email = esc_js( $access['email'] );
		ob_start();
		?>
		<div class="eduflow-portal-shell" style="max-width:1400px">
			<header class="eduflow-portal-header">
				<div><span class="eduflow-eyebrow">YTC Live Classroom</span><h1><?php echo esc_html( $access['batch_name'] ); ?></h1></div>
				<a class="eduflow-button eduflow-button-muted" href="<?php echo esc_url( wp_get_referer() ?: home_url( '/?eduflow_portal=student' ) ); ?>">Leave Classroom</a>
			</header>
			<div class="eduflow-portal-card" style="padding:0;overflow:hidden"><div id="eduflow-live-classroom" style="width:100%;height:78vh;min-height:560px;background:#111"></div></div>
		</div>
		<script src="https://<?php echo esc_attr( $access['domain'] ); ?>/external_api.js"></script>
		<script>
		(function(){
			var target=document.getElementById('eduflow-live-classroom');
			if(!target||typeof JitsiMeetExternalAPI==='undefined'){
				target.innerHTML='<div style="padding:30px;color:#fff">Live classroom could not load. Please refresh the page.</div>';
				return;
			}
			var api=new JitsiMeetExternalAPI('<?php echo $domain; ?>',{
				roomName:'<?php echo $room; ?>',
				parentNode:target,
				width:'100%',
				height:'100%',
				userInfo:{displayName:'<?php echo $display; ?>',email:'<?php echo $email; ?>'},
				configOverwrite:{prejoinPageEnabled:false,disableDeepLinking:true,startWithAudioMuted:false,startWithVideoMuted:false},
				interfaceConfigOverwrite:{SHOW_JITSI_WATERMARK:false,SHOW_WATERMARK_FOR_GUESTS:false,DISABLE_JOIN_LEAVE_NOTIFICATIONS:false}
			});
			api.addListener('readyToClose',function(){ window.history.length>1 ? window.history.back() : window.location.href='<?php echo esc_js( home_url( '/?eduflow_portal=student' ) ); ?>'; });
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	private static function notifications( $notifications ) {
		if ( ! $notifications ) {
			echo '<p>No notifications.</p>';
			return;
		}
		echo '<ul class="eduflow-notifications">';
		foreach ( $notifications as $notification ) {
			echo '<li><strong>' . esc_html( $notification['title'] ) . '</strong><span>' . esc_html( $notification['message'] ) . '</span><time>' . esc_html( $notification['created_at'] ) . '</time></li>';
		}
		echo '</ul>';
	}
}
