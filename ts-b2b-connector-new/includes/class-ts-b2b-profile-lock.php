<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Profile_Lock {

    public static function init() {
        // Admin-only pola w profilu usera
        add_action( 'show_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );

        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_fields' ), 10, 1 );
        add_action( 'user_register', array( __CLASS__, 'save_admin_user_fields' ), 10, 1 );

        // Finalny zapis po wszystkim (naprawia: Firma wraca pusta po aktualizacji usera)
        add_action( 'profile_update', array( __CLASS__, 'finalize_admin_user_fields_save' ), 999, 2 );

        // Twarda blokada prób zmiany meta przez partnera
        add_filter( 'update_user_metadata', array( __CLASS__, 'block_b2b_user_meta_updates' ), 10, 5 );

        // Twarda blokada próby zmiany user_email przez partnera
        add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'block_b2b_user_email_update' ), 10, 3 );

        // UI readonly w Moje konto / checkout
        add_action( 'wp_footer', array( __CLASS__, 'inject_b2b_readonly_js' ), 30 );
    }

    public static function add_admin_user_fields( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        $user_id = is_object($user) ? (int) $user->ID : 0;
        $company = get_user_meta( $user_id, 'billing_company', true );
        $nip     = get_user_meta( $user_id, 'billing_nip', true );
        ?>
        <h3>Dane Partnera B2B (admin)</h3>
        <table class="form-table">
            <tr>
                <th><label for="ts_b2b_billing_company">Firma</label></th>
                <td><input type="text" id="ts_b2b_billing_company" name="ts_b2b_billing_company" value="<?php echo esc_attr( $company ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="ts_b2b_billing_nip">NIP</label></th>
                <td><input type="text" id="ts_b2b_billing_nip" name="ts_b2b_billing_nip" value="<?php echo esc_attr( $nip ); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    public static function save_admin_user_fields( $user_id ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        if ( isset( $_POST['ts_b2b_billing_company'] ) ) {
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_company'] ) ) );
        }
        if ( isset( $_POST['ts_b2b_billing_nip'] ) ) {
            update_user_meta( $user_id, 'billing_nip', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_nip'] ) ) );
        }
    }

    public static function finalize_admin_user_fields_save( $user_id, $old_user_data ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        if ( isset( $_POST['ts_b2b_billing_company'] ) ) {
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_company'] ) ) );
        }
        if ( isset( $_POST['ts_b2b_billing_nip'] ) ) {
            update_user_meta( $user_id, 'billing_nip', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_nip'] ) ) );
        }
    }

    public static function block_b2b_user_meta_updates( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
        if ( is_admin() ) return $check;

        if ( current_user_can( 'manage_options' ) ) return $check;

        if ( ! is_user_logged_in() ) return $check;
        $current = get_current_user_id();
        if ( (int) $current !== (int) $object_id ) return $check;

        if ( TS_B2B_Core::is_strictly_b2b() && in_array( $meta_key, array( 'billing_company', 'billing_nip' ), true ) ) {
            // Zablokuj update – udaj sukces, ale nic nie zmieniaj
            return get_user_meta( $object_id, $meta_key, true );
        }

        return $check;
    }

    public static function block_b2b_user_email_update( $data, $update, $user_id ) {
        if ( is_admin() ) return $data;
        if ( current_user_can( 'manage_options' ) ) return $data;

        if ( ! $update ) return $data;
        if ( ! is_user_logged_in() ) return $data;

        if ( TS_B2B_Core::is_strictly_b2b() && (int) get_current_user_id() === (int) $user_id ) {
            $u = get_userdata( $user_id );
            if ( $u && ! empty( $u->user_email ) ) {
                $data['user_email'] = $u->user_email;
            }
        }

        return $data;
    }

    public static function inject_b2b_readonly_js() {
        if ( ! TS_B2B_Core::is_strictly_b2b() ) return;
        if ( is_admin() ) return;

        if ( function_exists('is_account_page') && ! is_account_page() && ( ! function_exists('is_checkout') || ! is_checkout() ) ) return;
        ?>
        <script>
        (function(){
            try {
                var selectors = [
                    'input#account_email',
                    'input[name="account_email"]',
                    'input#billing_email',
                    'input[name="billing_email"]',
                    'input#billing_company',
                    'input[name="billing_company"]',
                    'input#billing_nip',
                    'input[name="billing_nip"]'
                ];
                selectors.forEach(function(sel){
                    document.querySelectorAll(sel).forEach(function(el){
                        el.setAttribute('readonly', 'readonly');
                        el.addEventListener('keydown', function(e){ e.preventDefault(); });
                    });
                });
            } catch(e) {}
        })();
        </script>
        <?php
    }
}
