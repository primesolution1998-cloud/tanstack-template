<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Demo_Admin {
	public function register() {
		foreach ( array( 'book', 'move', 'update_booking', 'capacity', 'reschedule', 'cancel_booking', 'convert' ) as $action ) {
			add_action( 'admin_post_eduflow_demo_' . $action, array( $this, $action ) );
		}
	}

	private static function guard() {
		if ( ! current_user_can( 'eduflow_manage_classes' ) ) {
			wp_die( esc_html__( 'You cannot manage demo sessions.', 'eduflow-core' ) );
		}
	}

	private function redirect( $result, $success ) {
		$error = is_wp_error( $result );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'eduflow-demos',
					'eduflow_notice' => rawurlencode( $error ? $result->get_error_message() : $success ),
					'notice_type'    => $error ? 'error' : 'success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function notice() {
		if ( empty( $_GET['eduflow_notice'] ) ) {
			return;
		}
		$type = 'error' === ( $_GET['notice_type'] ?? '' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['eduflow_notice'] ) ) ) . '</p></div>';
	}

	public static function page() {
		self::guard();
		global $wpdb;
		$institute = EduFlow_Settings::institute_db_id();
		$sessions  = EduFlow_DB::table( 'demo_sessions' );
		$teachers  = EduFlow_DB::table( 'teachers' );
		$bookings  = EduFlow_DB::table( 'demo_bookings' );
		$events    = EduFlow_DB::table( 'google_events' );
		$search    = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$date      = sanitize_text_field( wp_unslash( $_GET['demo_date'] ?? '' ) );
		$page      = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$where     = array( 'd.institute_id=%d' );
		$params    = array( $institute );
		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(d.canonical_id LIKE %s OR t.name LIKE %s)';
			array_push( $params, $like, $like );
		}
		if ( $date ) {
			$where[] = 'd.demo_date=%s';
			$params[] = $date;
		}
		$base  = " FROM $sessions d INNER JOIN $teachers t ON t.id=d.teacher_id AND t.institute_id=d.institute_id LEFT JOIN $events g ON g.lecture_id=d.lecture_id AND g.institute_id=d.institute_id WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $base, ...$params ) );
		$params[] = 20;
		$params[] = ( $page - 1 ) * 20;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*,t.name AS teacher_name,g.google_meet_url,g.sync_status,(SELECT COUNT(*) FROM $bookings b WHERE b.institute_id=d.institute_id AND b.session_id=d.id AND b.booking_status='booked') AS booking_count" . $base . ' ORDER BY d.demo_date ASC,d.start_time ASC LIMIT %d OFFSET %d',
				...$params
			),
			ARRAY_A
		);
		$recent = $wpdb->get_results(
			$wpdb->prepare( "SELECT b.*,d.canonical_id,d.demo_date,d.start_time,d.end_time FROM $bookings b INNER JOIN $sessions d ON d.id=b.session_id AND d.institute_id=b.institute_id WHERE b.institute_id=%d ORDER BY b.updated_at DESC LIMIT 100", $institute ),
			ARRAY_A
		);
		self::notice();
		?>
		<div class="wrap eduflow-wrap">
			<h1><?php esc_html_e( 'Demo Sessions', 'eduflow-core' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="eduflow-demos">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Session ID or mentor">
				<input type="date" name="demo_date" value="<?php echo esc_attr( $date ); ?>">
				<?php submit_button( 'Filter', 'secondary', '', false ); ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Session</th><th>Date / Time</th><th>Mentor</th><th>Bookings</th><th>Meet</th><th>Status</th><th>Capacity</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['canonical_id'] ); ?></td>
						<td><?php echo esc_html( $row['demo_date'] . ' ' . $row['start_time'] . '–' . $row['end_time'] ); ?></td>
						<td><?php echo esc_html( $row['teacher_name'] ); ?></td>
						<td><?php echo esc_html( $row['booking_count'] . ' / ' . $row['capacity'] ); ?></td>
						<td>
						<?php if ( $row['google_meet_url'] ) : ?>
							<input class="regular-text eduflow-meet-link" readonly value="<?php echo esc_attr( $row['google_meet_url'] ); ?>">
							<button type="button" class="button eduflow-copy-meet">Copy Link</button>
						<?php else : ?>
							<?php echo esc_html( ucwords( str_replace( '_', ' ', $row['sync_status'] ?: 'pending' ) ) ); ?>
						<?php endif; ?>
						</td>
						<td><?php echo esc_html( ucfirst( $row['session_status'] ) ); ?></td>
						<td><?php self::capacity_form( $row ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="7">No demo sessions found.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $page, 'total' => max( 1, (int) ceil( $total / 20 ) ) ) ) ); ?>
			<?php self::booking_form(); ?>
			<?php self::session_operations(); ?>
			<?php self::bookings_table( $recent ); ?>
		</div>
		<script>document.addEventListener('click',function(e){if(e.target.classList.contains('eduflow-copy-meet')){var input=e.target.previousElementSibling;navigator.clipboard.writeText(input.value);}});</script>
		<?php
	}

	private static function capacity_form( $row ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eduflow_demo_capacity">
			<input type="hidden" name="session_id" value="<?php echo esc_attr( $row['id'] ); ?>">
			<?php wp_nonce_field( 'eduflow_demo_capacity_' . $row['id'] ); ?>
			<input type="number" min="1" name="capacity" value="<?php echo esc_attr( $row['capacity'] ); ?>" class="small-text">
			<button class="button">Update</button>
		</form>
		<?php
	}

	private static function teacher_options() {
		global $wpdb;
		$table = EduFlow_DB::table( 'teachers' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,canonical_id FROM $table WHERE institute_id=%d AND teacher_status=%s ORDER BY name", EduFlow_Settings::institute_db_id(), 'active' ), ARRAY_A );
		foreach ( $rows as $teacher ) {
			echo '<option value="' . esc_attr( $teacher['id'] ) . '">' . esc_html( $teacher['name'] . ' (' . $teacher['canonical_id'] . ')' ) . '</option>';
		}
	}

	private static function slot_fields( $capacity = true ) {
		?>
		<input type="date" name="demo_date" required>
		<input type="time" name="start_time" required>
		<input type="time" name="end_time" required>
		<select name="teacher_id" required><option value="">Select mentor</option><?php self::teacher_options(); ?></select>
		<?php if ( $capacity ) : ?>
			<input type="number" min="1" name="capacity" value="<?php echo esc_attr( EduFlow_Settings::get_all()['default_demo_slot_capacity'] ?? 6 ); ?>" placeholder="Capacity">
		<?php endif; ?>
		<?php
	}

	private static function booking_form() {
		?>
		<div class="eduflow-card">
			<h2>Book Demo Slot</h2>
			<p>Bookings with the same date, start/end time, and mentor automatically share one canonical session, Calendar event, and Meet.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eduflow_demo_book">
				<?php wp_nonce_field( 'eduflow_demo_book' ); ?>
				<div class="eduflow-form-grid">
					<label>Demo Date<input type="date" name="demo_date" required></label>
					<label>Start Time<input type="time" name="start_time" required></label>
					<label>End Time<input type="time" name="end_time" required></label>
					<label>Mentor<select name="teacher_id" required><option value="">Select mentor</option><?php self::teacher_options(); ?></select></label>
					<label>Slot Capacity<input type="number" min="1" name="capacity" value="<?php echo esc_attr( EduFlow_Settings::get_all()['default_demo_slot_capacity'] ?? 6 ); ?>"></label>
					<label>Prospect Name<input name="prospect_name" required></label>
					<label>Mobile<input name="mobile" required></label>
					<label>Email (optional)<input type="email" name="email"></label>
				</div>
				<?php submit_button( 'Book Demo' ); ?>
			</form>
		</div>
		<?php
	}

	private static function bookings_table( $bookings ) {
		?>
		<div class="eduflow-card">
			<h2>Individual Demo Bookings</h2>
			<table class="widefat striped">
				<thead><tr><th>Prospect</th><th>Contact</th><th>Session</th><th>Booking Details</th><th>Move</th></tr></thead>
				<tbody>
				<?php foreach ( $bookings as $booking ) : ?>
					<tr>
						<td><?php echo esc_html( $booking['prospect_name'] ); ?></td>
						<td><?php echo esc_html( $booking['mobile'] ); ?><br><?php echo esc_html( $booking['email'] ); ?></td>
						<td><?php echo esc_html( $booking['canonical_id'] . ' ' . $booking['demo_date'] . ' ' . $booking['start_time'] ); ?></td>
						<td><?php self::booking_update_form( $booking ); ?></td>
						<td><?php self::move_form( $booking ); self::cancel_form( $booking ); self::convert_form( $booking ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function booking_update_form( $booking ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eduflow_demo_update_booking">
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
			<?php wp_nonce_field( 'eduflow_demo_update_' . $booking['id'] ); ?>
			<select name="attendance_status">
			<?php foreach ( EduFlow_Demo_Service::ATTENDANCE_STATUSES as $value ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $booking['attendance_status'], $value ); ?>><?php echo esc_html( ucfirst( $value ) ); ?></option>
			<?php endforeach; ?>
			</select>
			<input name="feedback" value="<?php echo esc_attr( $booking['feedback'] ); ?>" placeholder="Feedback">
			<input name="follow_up_status" value="<?php echo esc_attr( $booking['follow_up_status'] ); ?>" placeholder="Follow-up">
			<input name="conversion_status" value="<?php echo esc_attr( $booking['conversion_status'] ); ?>" placeholder="Conversion">
			<button class="button">Save</button>
		</form>
		<?php
	}

	private static function move_form( $booking ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eduflow_demo_move">
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
			<?php wp_nonce_field( 'eduflow_demo_move_' . $booking['id'] ); ?>
			<?php self::slot_fields( false ); ?>
			<button class="button">Move</button>
		</form>
		<?php
	}

	private static function session_operations() {
		?><div class="eduflow-card"><h2>Reschedule Session / Change Mentor</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="eduflow_demo_reschedule"><?php wp_nonce_field('eduflow_demo_reschedule');?><input type="number" min="1" name="session_id" placeholder="Session database ID" required><?php self::slot_fields(false);?><button class="button">Update Shared Session</button></form></div><?php
	}

	private static function cancel_form( $booking ) {
		?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin-top:8px"><input type="hidden" name="action" value="eduflow_demo_cancel_booking"><input type="hidden" name="booking_id" value="<?php echo esc_attr($booking['id']);?>"><?php wp_nonce_field('eduflow_demo_cancel_'.$booking['id']);?><button class="button">Cancel Booking</button></form><?php
	}

	private static function convert_form( $booking ) {
		if ( $booking['admission_id'] ) { echo '<p>Admission #' . esc_html($booking['admission_id']) . '</p>'; return; }
		?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin-top:8px"><input type="hidden" name="action" value="eduflow_demo_convert"><input type="hidden" name="booking_id" value="<?php echo esc_attr($booking['id']);?>"><?php wp_nonce_field('eduflow_demo_convert_'.$booking['id']);?><input name="course" placeholder="Course" required><input name="level" placeholder="Level" required><select name="class_type"><option value="batch">Batch</option><option value="group">Group</option><option value="1-to-1">1-to-1</option><option value="demo">Demo</option><option value="trial">Trial</option></select><input type="number" min="0" step="0.01" name="total_fee" placeholder="Total fee"><button class="button">Convert to Admission</button></form><?php
	}

	public function book() {
		self::guard();
		check_admin_referer( 'eduflow_demo_book' );
		$input = wp_unslash( $_POST );
		$this->redirect( EduFlow_Demo_Service::add_booking( $input, $input ), 'Demo booking added to its canonical session.' );
	}

	public function move() {
		self::guard();
		$id = absint( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'eduflow_demo_move_' . $id );
		$this->redirect( EduFlow_Demo_Service::move_booking( $id, wp_unslash( $_POST ) ), 'Demo booking moved.' );
	}

	public function update_booking() {
		self::guard();
		$id = absint( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'eduflow_demo_update_' . $id );
		$this->redirect( EduFlow_Demo_Service::update_booking( $id, wp_unslash( $_POST ) ), 'Demo booking updated.' );
	}

	public function capacity() {
		self::guard();
		$id = absint( $_POST['session_id'] ?? 0 );
		check_admin_referer( 'eduflow_demo_capacity_' . $id );
		$this->redirect( EduFlow_Demo_Service::update_capacity( $id, absint( $_POST['capacity'] ?? 0 ) ), 'Demo capacity updated.' );
	}
	public function reschedule() { self::guard(); check_admin_referer('eduflow_demo_reschedule'); $id=absint($_POST['session_id']??0); $this->redirect(EduFlow_Demo_Service::reschedule_session($id,wp_unslash($_POST)),'Demo session updated.'); }
	public function cancel_booking() { self::guard(); $id=absint($_POST['booking_id']??0); check_admin_referer('eduflow_demo_cancel_'.$id); $this->redirect(EduFlow_Demo_Service::cancel_booking($id),'Demo booking cancelled.'); }
	public function convert() { self::guard(); $id=absint($_POST['booking_id']??0); check_admin_referer('eduflow_demo_convert_'.$id); $this->redirect(EduFlow_Demo_Service::convert_to_admission($id,wp_unslash($_POST)),'Demo converted to admission.'); }

}
