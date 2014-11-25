<?php

/**
 * The Gravity Forms For BP User Groups Plugin
 * 
 * @package Gravity Forms For BP User Groups
 * @subpackage Main
 */

/**
 * Plugin Name:       Gravity Forms For BP User Groups
 * Description:       Restrict per form access in Gravity Forms to BuddyPress user groups.
 * Plugin URI:        https://github.com/lmoffereins/gravityforms-for-bp-user-groups/
 * Version:           1.0.0
 * Author:            Laurens Offereins
 * Author URI:        https://github.com/lmoffereins/
 * Text Domain:       gravityforms-for-bp-user-groups
 * Domain Path:       /languages/
 * GitHub Plugin URI: lmoffereins/gravityforms-for-bp-user-groups
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GravityForms_For_BP_User_Groups' ) ) :
/**
 * The main plugin class
 *
 * @since 1.0.0
 */
final class GravityForms_For_BP_User_Groups {

	/**
	 * The plugin setting's main meta key
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $main_meta_key = 'forBPUserGroups';

	/**
	 * The plugin setting's groups meta key
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $groups_meta_key = 'selectedBPUserGroups';

	/**
	 * Setup and return the singleton pattern
	 *
	 * @since 1.0.0
	 *
	 * @uses GravityForms_For_BP_User_Groups::setup_globals()
	 * @uses GravityForms_For_BP_User_Groups::setup_actions()
	 * @return The single GravityForms_For_BP_User_Groups
	 */
	public static function instance() {

		// Store instance locally
		static $instance = null;

		if ( null === $instance ) {
			$instance = new GravityForms_For_BP_User_Groups;
			$instance->setup_globals();
			$instance->setup_actions();
		}

		return $instance;
	}

	/**
	 * Prevent the plugin class from being loaded more than once
	 */
	private function __construct() { /* Nothing to do */ }

	/** Private methods *************************************************/

	/**
	 * Define default class globals
	 *
	 * @since 1.0.0
	 */
	private function setup_globals() {

		// Detect BP Group Hierarchy plugin to support it
		$this->bp_group_hierarchy = defined( 'BP_GROUP_HIERARCHY_VERSION' );
	}

	/**
	 * Setup default actions and filters
	 *
	 * @since 1.0.0
	 */
	private function setup_actions() {

		// Displaying the form
		add_filter( 'gform_get_form_filter', array( $this, 'handle_form_display' ), 90, 2 );

		// Form settings
		add_filter( 'gform_form_settings',                            array( $this, 'register_form_setting'      ), 20, 2 );
		add_filter( 'gform_pre_form_settings_save',                   array( $this, 'update_form_setting'        )        );
		add_action( 'gravityforms_for_bp_user_groups_child_settings', array( $this, 'select_user_groups_setting' )        );
	}

	/** Public methods **************************************************/

	/**
	 * Return the given form's meta value
	 *
	 * @since 1.0.0
	 * 
	 * @param array|int $form Form object or form ID
	 * @param string $meta_key Form meta key
	 * @return mixed Form setting's value or NULL when not found
	 */
	public function get_form_meta( $form, $meta_key ) {

		// Get form metadata
		if ( ! is_array( $form ) && is_numeric( $form ) ) {
			$form = GFFormsModel::get_form_meta( (int) $form );
		} elseif ( ! is_array( $form ) ) {
			return null;
		}

		// Get form setting
		return isset( $form[ $meta_key ] ) ? $form[ $meta_key ] : null;
	}

	/**
	 * Do not display the form when the current user is not a member of associated user groups
	 *
	 * @since 1.0.0
	 *
	 * @uses get_current_user_id()
	 * @uses apply_filters() Calls 'gf_for_bp_user_groups_handle_form_display'
	 * 
	 * @param string $form_string The form response HTML
	 * @param array $form Form meta data
	 * @return string Form HTML
	 */
	public function handle_form_display( $form_string, $form ) {

		// Get the current user
		$user_id = get_current_user_id();

		// Form is marked for selected user groups
		if ( ! empty( $form ) && $this->get_form_meta( $form, $this->main_meta_key ) && 0 < count( $this->get_form_user_groups( $form ) ) ) {

			// User is not logged in
			if ( empty( $user_id ) ) {

				// Hide the form when login is not explicitly required
				if ( ! isset( $form['requireLogin'] ) || ! $form['requireLogin'] ) {

					// Display not-loggedin message
					$form_string = '<p>' . ( empty( $form['requireLoginMessage'] ) ? $this->get_gf_translation( 'Sorry. You must be logged in to view this form.' ) : GFCommon::gform_do_shortcode( $form['requireLoginMessage'] ) ) . '</p>';
				}

			// User is not member of this form's user groups. Hide the form
			} elseif ( ! $this->is_user_form_member( $form['id'], $user_id ) ) {
				$form_string = '<p>' . __( 'Sorry. You are not allowed to view this form.', 'gravityforms-for-bp-user-groups' ) . '</p>';
			}
		}

		return $form_string;
	}

	/**
	 * Shortcut to return the selected form's user groups
	 *
	 * @since 1.0.0
	 * 
	 * @param array|int $form Form object or form ID
	 * @return array Form's user groups
	 */
	public function get_form_user_groups( $form ) {
		$groups = $this->get_form_meta( $form, $this->groups_meta_key );

		// Default to empty array
		if ( empty( $groups ) )
			$groups = array();

		return $groups;
	}

	/**
	 * Return the given form's entries ids for the given user
	 *
	 * @since 1.0.0
	 *
	 * @uses get_current_user_id()
	 * @uses GravityForms_For_BP_User_Groups::get_form_user_groups()
	 * @uses BP_Groups_Hierarchy::has_children()
	 * @uses groups_get_groups()
	 * @uses apply_filters() Calls 'gravityforms_for_bp_user_groups_is_user_form_member'
	 * 
	 * @param array|int $form Form object or form ID
	 * @param int $user_id Optional. User ID. Defaults to current user ID
	 * @return bool The user can view the form
	 */
	public function is_user_form_member( $form, $user_id = 0 ) {
		global $wpdb;

		// Default to current user
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		// Get the form's assigned user groups
		$group_ids = $this->get_form_user_groups( $form );

		// Account for group hierarchy
		if ( ! empty( $group_ids ) && $this->bp_group_hierarchy ) {

			// Walk hierarchy
			$hierarchy = new ArrayIterator( $group_ids );
			foreach ( $hierarchy as $gid ) {

				// Add child group ids when found
				if ( $children = BP_Groups_Hierarchy::has_children( $gid ) ) {
					foreach ( $children as $child_id )
						$hierarchy->append( (int) $child_id );
				}
			}

			// Set hierarchy group id collection
			$group_ids = $hierarchy->getArrayCopy();
		}

		// Get groups by membership
		$groups = groups_get_groups( array(
			'user_id'         => $user_id,
			'include'         => $group_ids,
			'show_hidden'     => true,
			'per_page'        => false,
			'populate_extras' => false,
		) );

		return (bool) apply_filters( 'gravityforms_for_bp_user_groups_is_user_form_member', ! empty( $groups['groups'] ), $form, $user_id );
	}

	/**
	 * Return a translated string with the 'gravityforms' context
	 *
	 * @since 1.0.0
	 *
	 * @uses call_user_func_array() To call __() indirectly
	 * @param string $string String to be translated
	 * @return string Translation
	 */
	public function get_gf_translation( $string ) {
		return call_user_func_array( '__', array( $string, 'gravityforms' ) );
	}

	/** Admin Settings **************************************************/

	/**
	 * Register the plugin form settings fields
	 *
	 * @since 1.0.0
	 *
	 * @uses GravityForms_For_BP_User_Groups::get_form_meta()
	 * @uses GravityForms_For_BP_User_Groups::get_gf_translation()
	 * 
	 * @param array $settings Form settings sections with their fields
	 * @param array $form Form object
	 * @return array Form settings
	 */
	public function register_form_setting( $settings, $form ) {

		// Define local variable(s)
		$checked = $this->get_form_meta( $form, $this->main_meta_key );
		$style   = ! $checked ? 'style="display:none;"' : '';

		// Start output buffer and setup our settings fields markup
		ob_start(); ?>

		<tr>
			<th><?php _e( 'BP user groups', 'gravityforms-for-bp-user-groups' ); ?></th>
			<td>
				<input type="checkbox" name="for-bp-user-groups" id="gform_for_bp_user_groups" value="1" <?php checked( $checked ); ?> onclick="ToggleForUserGroups();" />
				<label for="gform_for_bp_user_groups"><?php _e( 'Restrict this form to the selected BuddyPress user groups', 'gravityforms-for-bp-user-groups' ); ?></label>

				<script type="text/javascript">
					function ToggleForUserGroups() {
						if ( jQuery( 'input[name="for-bp-user-groups"]' ).is(':checked') ) {
							ShowSettingRow( '#for_user_groups_setting' );
						} else {
							HideSettingRow( '#for_user_groups_setting' );
						}
					}
				</script>
			</td>
		</tr>

		<tr id="for_user_groups_setting" class="child_setting_row" <?php echo $style; ?>>
			<td colspan="2" class="gf_sub_settings_cell">
				<div class="gf_animate_sub_settings">
					<table>

						<?php // Provide hook for dynamic child settings ?>
						<?php do_action( 'gravityforms_for_bp_user_groups_child_settings', $form ); ?>

					</table>
				</div><!-- .gf_animate_sub_settings -->
			</td><!-- .gf_sub_settings_cell -->
		</tr>

		<?php

		// Settings sections are stored by their translatable title
		$section = $this->get_gf_translation( 'Restrictions' );

		// Append the field to the section and end the output buffer
		$settings[ $section ][ 'for_bp_user_groups' ] = ob_get_clean();

		return $settings;
	}

	/**
	 * Display the settings field to select user groups
	 *
	 * @since 1.0.1
	 *
	 * @uses GravityForms_For_BP_User_Groups::display_group_selection()
	 * @uses GravityForms_For_BP_User_Groups::get_form_user_groups()
	 * @param array $form Form object
	 */
	public function select_user_groups_setting( $form ) { ?>

		<tr>
			<th><?php _e( 'Groups', 'gravityforms-for-bp-user-groups' ); ?></th>
			<td><?php echo $this->display_group_selection( $this->get_form_user_groups( $form ) ); ?></td>
		</tr>

		<?php
	}

	/**
	 * Return the user group selection HTML
	 *
	 * @since 1.0.0
	 *
	 * @todo Display selectable groups hierarchically
	 *
	 * @uses groups_get_groups()
	 * 
	 * @param array $selected Selected group ids
	 * @param string $type The selection HTML type
	 * @return string Group selection HTML
	 */
	public function display_group_selection( $selected = array(), $type = 'list' ) {

		// Get selectable groups
		$groups = groups_get_groups( array( 'show_hidden' => true ) );
		$groups = $groups['groups'];

		// Start output buffer
		ob_start();

		// Switch selection type
		switch ( $type ) {
			case 'list' :

				// Start list
				?><ul><?php

				// Walk all groups
				foreach ( $groups as $group ) : ?>

					<li><label><input type="checkbox" name="selected-bp-user-groups[]" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $selected ) ); ?>> <?php echo $group->name; ?></label></li>

				<?php endforeach;

				// End list
				?></ul><?php

				break;
		}

		// Get and end output buffer
		$html = ob_get_clean();

		return apply_filters( 'gravityforms_for_bp_user_groups_display_group_selection', $html, $selected, $type );
	}

	/**
	 * Run the update form setting logic
	 *
	 * @since 1.0.0
	 * 
	 * @param array $settings Form settings
	 * @return array Form settings
	 */
	public function update_form_setting( $settings ) {

		// Sanitize form from $_POST var
		$settings[ $this->main_meta_key ] = isset( $_POST['for-bp-user-groups'] ) ? 1 : 0;

		// Sanitize selected user groups
		$settings[ $this->groups_meta_key ] = isset( $_POST['selected-bp-user-groups'] ) ? array_map( 'intval', $_POST['selected-bp-user-groups'] ) : array();

		return $settings;
	}
}

/**
 * Return single instance of this main plugin class
 *
 * @since 1.0.0
 * 
 * @return GravityForms_For_BP_User_Groups
 */
function gravityforms_for_bp_user_groups() {

	// Bail if either GF or BP user groups is not active
	if ( ! class_exists( 'GFForms' ) || ! function_exists( 'buddypress' ) || ! bp_is_active( 'groups' ) )
		return;

	return GravityForms_For_BP_User_Groups::instance();
}

// Initiate on plugins_loaded
add_action( 'plugins_loaded', 'gravityforms_for_bp_user_groups' );

endif; // class_exists
