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
}
add_action( 'bp_init', __NAMESPACE__ . '\\admin_init' );

/**
 * Add wp-admin UI menu to access the reports page.
 *
 * @since 1.0
 */
function add_admin_menu() {
	add_users_page(
		_x( 'Member Profile Data Reports', 'wp-admin screen title', 'export-humans' ),
		_x( 'Profile Reports', 'wp-admin menu label', 'export-humans' ),
		'manage_options',
		'hmnmd-export-humans',
		__NAMESPACE__ . '\\reports_screen'
	);
}

/**
 * Draw the reports screen.
 */
function reports_screen() {
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
			<?php _ex( 'Member Profile Data Reports', 'wp-admin screen title', 'export-humans' ); ?>
			<button class="page-title-action"><?php _e( 'Add Field', 'export-humans' ); ?></button>
		</h1>

		<table class="wp-list-table widefat fixed striped export-humans" id="export-humans-table">
			<thead>
				<tr>
					<td class="manage-column column-action action-column"></th>
					<th scope="col" class="column-primary column-field"><?php _e( 'Profile Field', 'export-humans' ); ?></th>
				</tr>
			</thead>

			<tbody>
				<tr>
					<th scope="row" class="action-column disabled">
						<span class="eh-delete-icon" title="<?php esc_attr_e( 'Delete', 'export-humans' ); ?>">
							<span class="screen-reader-text"><?php _e( 'Delete', 'export-humans' ); ?></span>
						</span>
					</th>
					<td class="select-column"><select><?php echo $select_html; ?></select></td>
				</tr>
			</tbody>
		</table>

		<input type="submit" class="button button-primary button-large" id="eh-submit" value="<?php esc_attr_e( 'Generate Report', 'export-humans' ); ?>">
	</div>

	<script type="text/html" id="tmpl-export-humans-row">
		<tr>
			<th scope="row" class="action-column">
				<span class="eh-delete-icon" title="<?php esc_attr_e( 'Delete', 'export-humans' ); ?>">
					<span class="screen-reader-text"><?php _e( 'Delete', 'export-humans' ); ?></span>
				</span>
			</th>
			<td class="select-column"><select><?php echo $select_html; ?></select></td>
		</tr>
	</script>

	<?php
}