<?php
namespace HumanMade\ExportHumans;

/**
 * Plugin Name: Export Humans
 * Plugin URI:  https://github.com/humanmade/Export-Humans
 * Description: Export profile field data from BuddyPress.
 * Author:      Paul Gibbs and Human Made
 * Author URI:  https://github.com/paulgibbs
 * Version:     1.0
 * Text Domain: export-humans
 * License:     GPLv2 or later.
 */

/**
 * Initialise the plugin.
 *
 * @since 1.0
 */
function admin_init() {
	if ( ! bp_is_active( 'xprofile' ) ) {
		return;
	}

	add_action( bp_core_admin_hook(), __NAMESPACE__ . '\\add_admin_menu' );

	add_filter( 'export_humans_profile_field_value', 'convert_chars'      );
	add_filter( 'export_humans_profile_field_value', 'force_balance_tags' );
	add_filter( 'export_humans_profile_field_value', 'xprofile_filter_format_field_value',         1, 2 );
	add_filter( 'export_humans_profile_field_value', 'xprofile_filter_format_field_value_by_type', 8, 3 );
}
add_action( 'bp_init', __NAMESPACE__ . '\\admin_init' );

/**
 * Add wp-admin UI menu to access the reports page.
 *
 * @since 1.0
 */
function add_admin_menu() {
	$hook = add_users_page(
		_x( 'Member Profile Data Reports', 'wp-admin screen title', 'export-humans' ),
		_x( 'Profile Reports', 'wp-admin menu label', 'export-humans' ),
		'manage_options',
		'export-humans',
		__NAMESPACE__ . '\\reports_screen'
	);

	add_action( "load-$hook", __NAMESPACE__ . '\\maybe_generate_report' );
}

/**
 * Handle form submission/report generation.
 *
 * @since 1.0
 */
function maybe_generate_report() {
	if ( ! bp_current_user_can( 'bp_moderate' ) ) {
		return;
	}

	if ( empty( $_GET['action'] ) || $_GET['action'] !== 'report' || empty( $_POST['ids'] ) ) {
		return;
	}

	check_admin_referer( 'export-humans', '_ehnonce' );
	generate_report( array_filter( wp_parse_id_list( $_POST['ids'] ) ) );

	exit;
}

/**
 * Draw the reports screen.
 *
 * @since 1.0
 */
function reports_screen() {
	$form_url     = remove_query_arg( array( 'action' ), $_SERVER['REQUEST_URI'] );
	$form_url     = add_query_arg( 'action', 'report', $form_url );
	$field_groups = array();
	$select_html  = '<option></option>';

	wp_enqueue_script( 'export-humans-js', plugin_dir_url( __FILE__ ) . 'admin.js', array( 'underscore', 'wp-util' ) );
	wp_enqueue_style( 'export-humans-css', plugin_dir_url( __FILE__ ) . 'admin.css' );


	// Fields groups
	$raw_groups = bp_profile_get_field_groups();
	foreach ( $raw_groups as $raw_fields ) {

		// Handle bug: https://buddypress.trac.wordpress.org/ticket/7154
		if ( ! isset( $raw_fields->fields  )) {
			continue;
		}

		// Fields
		$field_groups[ $raw_fields->name ] = wp_list_pluck( $raw_fields->fields, 'name', 'id' );
	}
	unset( $raw_groups );


	// Build HTML for select box.
	foreach ( $field_groups as $group_name => $fields ) {
		$options = '';

		foreach ( $fields as $field_id => $field_name ) {
			$options .= sprintf( '<option value="%s">%s</option>', esc_attr( $field_id ), esc_html( $field_name ) );
		}

		$select_html .= sprintf( '<optgroup label="%s">%s</optgroup>', esc_attr( $group_name ), $options );
	}
	?>

	<div class="wrap">
		<h1>
			<?php echo esc_html( _x( 'Member Profile Data Reports', 'wp-admin screen title', 'export-humans' ) ); ?>
			<button class="page-title-action"><?php esc_html_e( 'Add Field', 'export-humans' ); ?></button>
		</h1>

		<form action="<?php echo esc_url( $form_url ); ?>" method="post" target="_blank">
			<table class="wp-list-table widefat fixed striped export-humans" id="export-humans-table">
				<thead>
					<tr>
						<td class="manage-column column-action action-column"></th>
						<th scope="col" class="column-primary column-field"><?php esc_html_e( 'Profile Field', 'export-humans' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<tr>
						<th scope="row" class="action-column disabled">
							<span class="eh-delete-icon" title="<?php esc_attr_e( 'Delete', 'export-humans' ); ?>">
								<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'export-humans' ); ?></span>
							</span>
						</th>
						<td class="select-column"><select name="ids[]"><?php echo $select_html; ?></select></td>
					</tr>
				</tbody>
			</table>

			<?php wp_nonce_field( 'export-humans', '_ehnonce' ); ?>
			<input type="submit" class="button button-primary button-large" id="eh-submit" value="<?php esc_attr_e( 'Generate Report', 'export-humans' ); ?>">
		</form>
	</div>

	<script type="text/html" id="tmpl-export-humans-row">
		<tr>
			<th scope="row" class="action-column">
				<span class="eh-delete-icon" title="<?php esc_attr_e( 'Delete', 'export-humans' ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'export-humans' ); ?></span>
				</span>
			</th>
			<td class="select-column"><select name="ids[]"><?php echo $select_html; ?></select></td>
		</tr>
	</script>

	<?php
}

/**
 * Generate a report.
 *
 * Outputs to the browser as a file requesting download.
 *
 * @since 1.0
 *
 * @param array $profile_field_ids Array of integers.
 */
function generate_report( $profile_field_ids ) {
	$filename   = sanitize_file_name( sprintf( 'facts-report-%s', date( 'Y-m-d-U' ) ) );
	$first_loop = true;
	$headings   = array( __( 'Name', 'export-humans' ) );
	$report     = array();
	$users      = get_users( array( 'fields' => array( 'display_name', 'ID' ) ) );


	// Generate the report.
	foreach ( $users as $user ) {
		if ( ! bp_is_user_active( $user->ID ) ) {
			continue;
		}

		$report[ "{$user->display_name}" ] = array( $user->display_name );

		foreach ( $profile_field_ids as $field_id ) {
			$field      = xprofile_get_field( $field_id );
			$field_data = xprofile_get_field_data( $field_id, $user->ID );
			$field_data = apply_filters( 'export_humans_profile_field_value', $field_data, $field->type, $field->id );

			$report[ "{$user->display_name}" ][] = $field_data;

			if ( $first_loop ) {
				$headings[] = $field->name;
			}
		}

		$first_loop = false;
	}


	// Send the report.
	header( 'Content-Description: File Transfer' );
	header( "Content-Disposition: attachment; filename={$filename}.csv" );
	header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ), true );

	$fh = @fopen( 'php://output', 'w' );
	fwrite( $fh, "\xEF\xBB\xBF" );
	fputcsv( $fh, $headings );

	foreach ( $report as $data ) {
		fputcsv( $fh, $data );
	}

	fclose( $fh );
	exit;
}
