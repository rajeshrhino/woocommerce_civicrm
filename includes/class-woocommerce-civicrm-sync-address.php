<?php

/**
 * Woocommerce CiviCRM Sync Address class.
 *
 * @since 2.0
 */

class Woocommerce_CiviCRM_Sync_Address {

	/**
	 * Initialises this object.
	 *
	 * @since 2.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.2
	 */
	public function register_hooks() {
		// Sync Woocommerce and Civicrm address for contact/user
		add_action( 'civicrm_post', array( $this, 'sync_civi_contact_address' ), 10, 4 );
		// Sync Woocommerce and Civicrm address for user/contact
		add_action( 'woocommerce_customer_save_address', array( $this, 'sync_wp_user_woocommerce_address' ), 10, 2 );
	}

	/**
	 * Sync Civicrm address for contact->user.
	 *
	 * Fires when a Civi contact's address is edited.
	 * @since 2.0
	 * @param string $op The operation being performed
	 * @param string $objectName The entity name
	 * @param int $objectId The entity id
	 * @param object $objectRef The entity object
	 */
	public function sync_civi_contact_address( $op, $objectName, $objectId, $objectRef ){

		// abbort if sync is not enabled
		if( ! Woocommerce_CiviCRM_Helper::$instance->check_yes_no_value( get_option( 'woocommerce_civicrm_sync_contact_address' ) ) ) return;

		if ( $op != 'edit' ) return;

		if ( $objectName != 'Address' ) return;

		// Abort if the address being edited is not one of the mapped ones
		if( ! in_array( $objectRef->location_type_id, Woocommerce_CiviCRM_Helper::$instance->mapped_location_types ) ) return;

		// abort if we don't have a contact_id
		if ( ! isset( $objectRef->contact_id ) ) return;

		$cms_user = Woocommerce_CiviCRM_Helper::$instance->get_civicrm_ufmatch( $objectRef->contact_id, 'contact_id' );

		// abort if we don't have a WordPress user_id
		if ( ! $cms_user ) return;

		// Proceed
		$address_type = array_search( $objectRef->location_type_id, Woocommerce_CiviCRM_Helper::$instance->mapped_location_types );

		foreach ( Woocommerce_CiviCRM_Helper::instance()->get_mapped_address( $address_type ) as $wc_field => $civi_field ) {
			if ( ! empty( $objectRef->{$civi_field} ) && ! is_null( $objectRef->{$civi_field} ) && $objectRef->{$civi_field} != 'null' ) {

				switch ( $civi_field ) {
					case 'country_id':
						update_user_meta( $cms_user['uf_id'], $wc_field, Woocommerce_CiviCRM_Helper::$instance->get_civi_country_iso_code( $objectRef->{$civi_field} ) );
						continue 2;
					case 'state_province_id':
						update_user_meta( $cms_user['uf_id'], $wc_field, Woocommerce_CiviCRM_Helper::$instance->get_civi_state_province_name( $objectRef->{$civi_field} ) );
						continue 2;
					default:
						update_user_meta( $cms_user['uf_id'], $wc_field, $objectRef->{$civi_field} );
						continue 2;
				}
			}
		}

		/**
		 * Broadcast that a Woocommerce address has been updated for a user.
		 *
		 * @since 2.0
		 * @param int $user_id The WordPress user id
		 * @param string $address_type The Woocommerce adress type 'billing' || 'shipping'
		 */
		do_action( 'woocommerce_civicrm_wc_address_updated', $cms_user['uf_id'], $address_type );

	}

	/**
	 * Sync Woocommerce address for user->contact.
	 *
	 * Fires when Woocomerce address is edited.
	 * @since 2.0
	 * @param int $user_id The WP user_id
	 * @param string $load_address The address type 'shipping' | 'billing'
	 */
	public function sync_wp_user_woocommerce_address( $user_id, $load_address ){

		// abbort if sync is not enabled
		if( ! Woocommerce_CiviCRM_Helper::$instance->check_yes_no_value( get_option( 'woocommerce_civicrm_sync_contact_address' ) ) ) return;

		$customer = new WC_Customer( $user_id );

		$civi_contact = Woocommerce_CiviCRM_Helper::$instance->get_civicrm_ufmatch( $user_id, 'uf_id' );

		// abort if we don't have a CiviCRM contact
		if ( ! $civi_contact ) return;

		$mapped_location_types = Woocommerce_CiviCRM_Helper::$instance->mapped_location_types;
		$civi_address_location_type = $mapped_location_types[$load_address];

		$edited_address = array();
		foreach ( Woocommerce_CiviCRM_Helper::$instance->get_mapped_address( $load_address ) as $wc_field => $civi_field ) {
			switch ( $civi_field ) {
				case 'country_id':
					$edited_address[$civi_field] = Woocommerce_CiviCRM_Helper::$instance->get_civi_country_id( $customer->{'get_' . $wc_field}() );
					continue 2;
				case 'state_province_id':
					$edited_address[$civi_field] = Woocommerce_CiviCRM_Helper::$instance->get_civi_state_province_id( $customer->{'get_' . $wc_field}(), $edited_address['country_id'] );
					continue 2;
				default:
					$edited_address[$civi_field] = $customer->{'get_' . $wc_field}();
					continue 2;
			}
		}

		$params = array(
			'contact_id' => $civi_contact['contact_id'],
			'location_type_id' => $civi_address_location_type,
		);

		try {
			$civi_address = civicrm_api3( 'Address', 'getsingle', $params );
		} catch ( CiviCRM_Exception $e ) {
			CRM_Core_Error::debug_log_message( $e->getMessage() );
		}

		try {
			if ( isset( $civi_address ) && ! $civi_address['is_error'] ) {
				$new_params = array_merge( $civi_address, $edited_address );
			} else {
				$new_params = array_merge( $params, $edited_address );
			}
			$create_address = civicrm_api3( 'Address', 'create', $new_params );
		} catch ( CiviCRM_Exception $e ) {
			CRM_Core_Error::debug_log_message( $e->getMessage() );
		}

		/**
		 * Broadcast that a CiviCRM address has been updated.
		 *
		 * @since 2.0
		 * @param int $contact_id The CiviCRM contact_id
		 * @param array $address The CiviCRM edited address
		 */
		do_action( 'woocommerce_civicrm_civi_address_updated', $civi_contact['contact_id'], $create_address );
	}
}
