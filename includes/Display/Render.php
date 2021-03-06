<?php if ( ! defined( 'ABSPATH' ) ) exit;

final class NF_Display_Render
{
    protected static $loaded_templates = array(
        'app-layout',
        'app-before-form',
        'app-after-form',
        'app-before-fields',
        'app-after-fields',
        'app-before-field',
        'app-after-field',
        'form-layout',
        'form-hp',
        'field-layout',
        'field-before',
        'field-after',
        'fields-wrap',
        'fields-wrap-no-label',
        'fields-wrap-no-container',
        'fields-label',
        'fields-error',
        'form-error',
        'field-input-limit',
        'field-null'
    );

    protected static $use_test_values = FALSE;

    public static function localize( $form_id )
    {
        $capability = apply_filters( 'ninja_forms_display_test_values_capabilities', 'read' );
        if( isset( $_GET[ 'ninja_forms_test_values' ] ) && current_user_can( $capability ) ){
            self::$use_test_values = TRUE;
        }

        if( ! has_action( 'wp_footer', 'NF_Display_Render::output_templates', 9999 ) ){
            add_action( 'wp_footer', 'NF_Display_Render::output_templates', 9999 );
        }
        $form = Ninja_Forms()->form( $form_id )->get();

        if( $form->get_setting( 'logged_in' ) && ! is_user_logged_in() ){
            echo $form->get_setting( 'not_logged_in_msg' );
            return;
        }

        if( $form->get_setting( 'sub_limit_number' ) ){
            $subs = Ninja_Forms()->form( $form_id )->get_subs();

            // TODO: Optimize Query
            global $wpdb;
            $count = 0;
            $subs = $wpdb->get_results( "SELECT post_id FROM wp_postmeta WHERE `meta_key` = '_form_id' AND `meta_value` = $form_id" );
            foreach( $subs as $sub ){
                if( 'publish' == get_post_status( $sub->post_id ) ) $count++;
            }

            if( $count >= $form->get_setting( 'sub_limit_number' ) ) {
                echo apply_filters( 'nf_sub_limit_reached_msg', $form->get_setting( 'sub_limit_msg' ), $form_id );
                return;
            }
        }

        $currency = $form->get_setting( 'currency', Ninja_Forms()->get_setting( 'currency' ) );
        $currency_symbol = Ninja_Forms::config( 'CurrencySymbol' );
        $form->update_setting( 'currency_symbol', ( isset( $currency_symbol[ $currency ] ) ) ? $currency_symbol[ $currency ] : '' );

        $title = apply_filters( 'ninja_forms_form_title', $form->get_setting( 'title' ), $form_id );
        $form->update_setting( 'title', $title );

        $before_form = apply_filters( 'ninja_forms_display_before_form', '', $form_id );
        $form->update_setting( 'beforeForm', $before_form );

        $before_fields = apply_filters( 'ninja_forms_display_before_fields', '', $form_id );
        $form->update_setting( 'beforeFields', $before_fields );

        $after_fields = apply_filters( 'ninja_forms_display_after_fields', '', $form_id );
        $form->update_setting( 'afterFields', $after_fields );

        $after_form = apply_filters( 'ninja_forms_display_after_form', '', $form_id );
        $form->update_setting( 'afterForm', $after_form );

        $form_fields = Ninja_Forms()->form( $form_id )->get_fields();

        $fields = array();

        if( empty( $form_fields ) ){
            echo __( 'No Fields Found.', 'ninja-forms' );
        } else {
            foreach ($form_fields as $field) {

                $field_type = $field->get_settings('type');

                if( ! isset( Ninja_Forms()->fields[ $field_type ] ) ) {
                    $field = NF_Fields_Unknown::create( $field );
                    $field_type = $field->get_setting( 'type' );
                }

                $field = apply_filters('ninja_forms_localize_fields', $field);
                $field = apply_filters('ninja_forms_localize_field_' . $field_type, $field);

                $field_class = Ninja_Forms()->fields[$field_type];

                if (self::$use_test_values) {
                    $field->update_setting('value', $field_class->get_test_value());
                }

                $field->update_setting('id', $field->get_id());

                /*
                 * TODO: For backwards compatibility, run the original action, get contents from the output buffer, and return the contents through the filter. Also display a PHP Notice for a deprecate filter.
                 */

                $display_before = apply_filters( 'ninja_forms_display_before_field_type_' . $field->get_setting( 'type' ), '' );
                $display_before = apply_filters( 'ninja_forms_display_before_field_key_' . $field->get_setting( 'key' ), $display_before );
                $field->update_setting( 'beforeField', $display_before );

                $display_after = apply_filters( 'ninja_forms_display_after_field_type_' . $field->get_setting( 'type' ), '' );
                $display_after = apply_filters( 'ninja_forms_display_after_field_key_' . $field->get_setting( 'key' ), $display_after );
                $field->update_setting( 'afterField', $display_after );

                $templates = $field_class->get_templates();

                if (!array($templates)) {
                    $templates = array($templates);
                }

                foreach ($templates as $template) {
                    self::load_template('fields-' . $template);
                }

                $settings = $field->get_settings();

                foreach ($settings as $key => $setting) {
                    if (is_numeric($setting)) $settings[$key] = floatval($setting);
                }

                if( ! isset( $settings[ 'label_pos' ] ) || 'default' == $settings[ 'label_pos' ] ){
                    $settings[ 'label_pos' ] = $form->get_setting( 'default_label_pos' );
                }

                $settings[ 'parentType' ] = $field_class->get_parent_type();

                if( 'list' == $settings[ 'parentType' ] && isset( $settings[ 'options' ] ) && is_array( $settings[ 'options' ] ) ){
                    $settings[ 'options' ] = apply_filters( 'ninja_forms_render_options', $settings[ 'options' ], $settings );
                    $settings[ 'options' ] = apply_filters( 'ninja_forms_render_options_' . $field_type, $settings[ 'options' ], $settings );
                }

                if (isset($settings['default'])) {
                    $default_value = apply_filters('ninja_forms_render_default_value', $settings['default'], $field_type, $settings);

                    $default_value = preg_replace( '/{.*}/', '', $default_value );

                    if ($default_value) {
                        $settings['value'] = $default_value;

                        ob_start();
                        do_shortcode( $settings['value'] );
                        $ob = ob_get_clean();

                        if( $ob ){
                            $settings['value'] = $ob;
                        }
                    }
                }

                // TODO: Find a better way to do this.
                if ('shipping' == $settings['type']) {
                    $settings['shipping_cost'] = preg_replace ('/[^\d,\.]/', '', $settings['shipping_cost']);
                    $settings['shipping_cost'] = str_replace( Ninja_Forms()->get_setting( 'currency_symbol' ), '', $settings['shipping_cost']);
                    $settings['shipping_cost'] = number_format($settings['shipping_cost'], 2);
                } elseif ('product' == $settings['type']) {
                    $settings['product_price'] = preg_replace ('/[^\d,\.]/', '', $settings[ 'product_price' ] );
                    $settings['product_price'] = str_replace( Ninja_Forms()->get_setting( 'currency_symbol' ), '', $settings['product_price']);
                    $settings['product_price'] = number_format($settings['product_price'], 2);
                } elseif ('total' == $settings['type'] && isset($settings['value'])) {
                    $settings['value'] = number_format($settings['value'], 2);
                }

                $settings['element_templates'] = $templates;
                $settings['old_classname'] = $field_class->get_old_classname();
                $settings['wrap_template'] = $field_class->get_wrap_template();

                $fields[] = apply_filters( 'ninja_forms_localize_field_settings_' . $field_type, $settings, $form );
            }
        }

        // Output Form Container
        do_action( 'ninja_forms_before_container', $form_id, $form->get_settings(), $form_fields );
        Ninja_Forms::template( 'display-form-container.html.php', compact( 'form_id' ) );

        ?>
        <!-- TODO: Move to Template File. -->
        <script>
            var formDisplay = 1;

            // Maybe initialize nfForms object
            var nfForms = nfForms || [];

            // Build Form Data
            var form = [];
            form.id = '<?php echo $form_id; ?>';
            form.settings = <?php echo wp_json_encode( $form->get_settings() ); ?>;

            form.fields = <?php echo wp_json_encode( $fields ); ?>;

            // Add Form Data to nfForms object
            nfForms.push( form );
        </script>

        <?php
        self::enqueue_scripts( $form_id );
    }

    public static function localize_preview( $form_id )
    {
        $capability = apply_filters( 'ninja_forms_display_test_values_capabilities', 'read' );
        if( isset( $_GET[ 'ninja_forms_test_values' ] ) && current_user_can( $capability ) ){
            self::$use_test_values = TRUE;
        }

        add_action( 'wp_footer', 'NF_Display_Render::output_templates', 9999 );

        $form = get_user_option( 'nf_form_preview_' . $form_id );

        if( ! $form ){
            self::localize( $form_id );
            return;
        }

        if( isset( $form[ 'settings' ][ 'logged_in' ] ) && $form[ 'settings' ][ 'logged_in' ] && ! is_user_logged_in() ){
            echo $form[ 'settings' ][ 'not_logged_in_msg' ];
            return;
        }

        $form[ 'settings' ][ 'is_preview' ] = TRUE;

        $currency = ( isset( $form[ 'settings' ][ 'currency' ] ) && $form[ 'settings' ][ 'currency' ] ) ? $form[ 'settings' ][ 'currency' ] : Ninja_Forms()->get_setting( 'currency' ) ;
        $currency_symbol = Ninja_Forms::config( 'CurrencySymbol' );
        $form[ 'settings' ][ 'currency_symbol' ] = ( isset( $currency_symbol[ $currency ] ) ) ? $currency_symbol[ $currency ] : '';

        $before_form = apply_filters( 'ninja_forms_display_before_form', '', $form_id, TRUE );
        $form[ 'settings' ][ 'beforeForm'] = $before_form;

        $before_fields = apply_filters( 'ninja_forms_display_before_fields', '', $form_id, TRUE );
        $form[ 'settings' ][ 'beforeFields'] = $before_fields;

        $after_fields = apply_filters( 'ninja_forms_display_after_fields', '', $form_id, TRUE );
        $form[ 'settings' ][ 'afterFields'] = $after_fields;

        $after_form = apply_filters( 'ninja_forms_display_after_form', '', $form_id, TRUE );
        $form[ 'settings' ][ 'afterForm'] = $after_form;

        $fields = array();

        if( empty( $form['fields'] ) ){
            echo __( 'No Fields Found.', 'ninja-forms' );
        } else {
            foreach ($form['fields'] as $field_id => $field) {

                $field_type = $field['settings']['type'];

                if( ! isset( Ninja_Forms()->fields[ $field_type ] ) ) continue;
                if( ! apply_filters( 'ninja_forms_preview_display_type_' . $field_type, TRUE ) ) continue;
                if( ! apply_filters( 'ninja_forms_preview_display_field', $field ) ) continue;

                $field['settings']['id'] = $field_id;

                $field = apply_filters('ninja_forms_localize_fields_preview', $field);
                $field = apply_filters('ninja_forms_localize_field_' . $field_type . '_preview', $field);

                $display_before = apply_filters( 'ninja_forms_display_before_field_type_' . $field['settings'][ 'type' ], '' );
                $display_before = apply_filters( 'ninja_forms_display_before_field_key_' . $field['settings'][ 'key' ], $display_before );
                $field['settings'][ 'beforeField' ] = $display_before;

                $display_after = apply_filters( 'ninja_forms_display_after_field_type_' . $field['settings'][ 'type' ], '' );
                $display_after = apply_filters( 'ninja_forms_display_after_field_key_' . $field['settings'][ 'key' ], $display_after );
                $field['settings'][ 'afterField' ] = $display_after;

                foreach ($field['settings'] as $key => $setting) {
                    if (is_numeric($setting)) $field['settings'][$key] = floatval($setting);
                }

                if( ! isset( $field['settings'][ 'label_pos' ] ) || 'default' == $field['settings'][ 'label_pos' ] ){
                    if( isset( $form[ 'settings' ][ 'default_label_pos' ] ) ) {
                        $field['settings'][ 'label_pos' ] = $form[ 'settings' ][ 'default_label_pos' ];
                    }
                }

                $field_class = Ninja_Forms()->fields[$field_type];

                $templates = $field_class->get_templates();

                if (!array($templates)) {
                    $templates = array($templates);
                }

                foreach ($templates as $template) {
                    self::load_template('fields-' . $template);
                }

                if (self::$use_test_values) {
                    $field['settings']['value'] = $field_class->get_test_value();
                }

                $field[ 'settings' ][ 'parentType' ] = $field_class->get_parent_type();

                if( 'list' == $field[ 'settings' ][ 'parentType' ] && isset( $field['settings'][ 'options' ] ) && is_array( $field['settings'][ 'options' ] ) ){
                    $field['settings'][ 'options' ] = apply_filters( 'ninja_forms_render_options', $field['settings'][ 'options' ], $field['settings'] );
                }

                if (isset($field['settings']['default'])) {
                    $default_value = apply_filters('ninja_forms_render_default_value', $field['settings']['default'], $field_type, $field['settings']);

                    $default_value = preg_replace( '/{.*}/', '', $default_value );

                    if ($default_value) {
                        $field['settings']['value'] = $default_value;

                        ob_start();
                        do_shortcode( $field['settings']['value'] );
                        $ob = ob_get_clean();

                        if( $ob ){
                            $field['settings']['value'] = $ob;
                        }
                    }
                }

                // TODO: Find a better way to do this.
                if ('shipping' == $field['settings']['type']) {
                    $field['settings']['shipping_cost'] = preg_replace ('/[^\d,\.]/', '', $field['settings']['shipping_cost'] );
                    $field['settings']['shipping_cost'] = str_replace( Ninja_Forms()->get_setting( 'currency_symbol' ), '', $field['settings']['shipping_cost'] );
                    $field['settings']['shipping_cost'] = number_format($field['settings']['shipping_cost'], 2);
                } elseif ('product' == $field['settings']['type']) {
                    // TODO: Does the currency marker need to stripped here?
                    $field['settings']['product_price'] = preg_replace ('/[^\d,\.]/', '', $field['settings']['product_price'] );
                    $field['settings']['product_price'] = str_replace( Ninja_Forms()->get_setting( 'currency_symbol' ), '', $field['settings']['product_price'] );
                    $field['settings']['product_price'] = number_format($field['settings']['product_price'], 2);
                } elseif ('total' == $field['settings']['type']) {
                    if( ! isset( $field[ 'settings' ][ 'value' ] ) ) $field[ 'settings' ][ 'value' ] = 0;
                    $field['settings']['value'] = number_format($field['settings']['value'], 2);
                }

                $field['settings']['element_templates'] = $templates;
                $field['settings']['old_classname'] = $field_class->get_old_classname();
                $field['settings']['wrap_template'] = $field_class->get_wrap_template();

                $fields[] = apply_filters( 'ninja_forms_localize_field_settings_' . $field_type, $field['settings'], $form );
            }
        }

        // Output Form Container
        do_action( 'ninja_forms_before_container_preview', $form_id, $form[ 'settings' ], $fields );
        Ninja_Forms::template( 'display-form-container.html.php', compact( 'form_id' ) );

        ?>
        <!-- TODO: Move to Template File. -->
        <script>
            // Maybe initialize nfForms object
            var nfForms = nfForms || [];

            // Build Form Data
            var form = [];
            form.id = '<?php echo $form['id']; ?>';
            form.settings = JSON.parse( '<?php echo WPN_Helper::addslashes( wp_json_encode( $form['settings'] ) ); ?>' );

            form.fields = JSON.parse( '<?php echo WPN_Helper::addslashes( wp_json_encode(  $fields ) ); ?>' );

            // Add Form Data to nfForms object
            nfForms.push( form );
        </script>

        <?php
        self::enqueue_scripts( $form_id );
    }

    public static function enqueue_scripts( $form_id )
    {
        $form = Ninja_Forms()->form( $form_id )->get();
        $is_preview = ( $form->get_tmp_id() );

        $ver     = Ninja_Forms::VERSION;
        $js_dir  = Ninja_Forms::$url . 'assets/js/min/';
        $css_dir = Ninja_Forms::$url . 'assets/css/';


        switch( Ninja_Forms()->get_setting( 'opinionated_styles' ) ) {
            case 'light':
                wp_enqueue_style( 'nf-display',      $css_dir . 'display-opinions-light.css' );
                wp_enqueue_style( 'nf-font-awesome', $css_dir . 'font-awesome.min.css'       );
                break;
            case 'dark':
                wp_enqueue_style( 'nf-display',      $css_dir . 'display-opinions-dark.css' );
                wp_enqueue_style( 'nf-font-awesome', $css_dir . 'font-awesome.min.css'      );
                break;
            default:
                wp_enqueue_style( 'nf-display',      $css_dir . 'display-structure.css'     );
        }

        if( $is_preview || self::form_uses_recaptcha( $form_id ) ) {
            $recaptcha_lang = Ninja_Forms()->get_setting('recaptcha_lang');
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?hl=' . $recaptcha_lang, array( 'jquery' ), $ver );
        }

        if( $is_preview || self::form_uses_datepicker( $form_id ) ) {
            wp_enqueue_style( 'pikaday-responsive', $css_dir . 'pikaday-package.css', $ver );
            wp_enqueue_script('nf-front-end--datepicker', $js_dir . 'front-end--datepicker.min.js', array( 'jquery' ), $ver );
        }

        if( $is_preview || self::form_uses_inputmask( $form_id ) ) {
            wp_enqueue_script('nf-front-end--inputmask', $js_dir . 'front-end--inputmask.min.js', array( 'jquery' ), $ver );
        }

         if( $is_preview || self::form_uses_rte( $form_id ) ) {
             if( $is_preview || self::form_uses_textarea_media( $form_id ) ) {
                wp_enqueue_media();
             }

            wp_enqueue_style( 'summernote',         $css_dir . 'summernote.css'   , $ver );
            wp_enqueue_style( 'codemirror',         $css_dir . 'codemirror.css'   , $ver );
            wp_enqueue_style( 'codemirror-monokai', $css_dir . 'monokai-theme.css', $ver );
            wp_enqueue_script('nf-front-end--rte', $js_dir . 'front-end--rte.min.js', array( 'jquery' ), $ver );
         }

        if( $is_preview || self::form_uses_helptext( $form_id ) ) {
            wp_enqueue_style( 'jBox', $css_dir . 'jBox.css', $ver );
            wp_enqueue_script('nf-front-end--helptext', $js_dir . 'front-end--helptext.min.js', array( 'jquery' ), $ver );
        }

        if( $is_preview || self::form_uses_starrating( $form_id ) ) {
            wp_enqueue_style( 'rating', $css_dir . 'rating.css', Ninja_Forms::VERSION );
            wp_enqueue_script('nf-front-end--starrating', $js_dir . 'front-end--starrating.min.js', array( 'jquery' ), $ver );
        }

        wp_enqueue_script( 'nf-front-end-deps', $js_dir . 'front-end-deps.js', array( 'jquery', 'backbone' ), $ver );
        wp_enqueue_script( 'nf-front-end',      $js_dir . 'front-end.js',      array( 'nf-front-end-deps'  ), $ver );

        wp_localize_script( 'nf-front-end', 'nfi18n', Ninja_Forms::config( 'i18nFrontEnd' ) );

        $data = apply_filters( 'ninja_forms_render_localize_script_data', array(
            'ajaxNonce' => wp_create_nonce( 'ninja_forms_display_nonce' ),
            'adminAjax' => admin_url( 'admin-ajax.php' ),
            'requireBaseUrl' => Ninja_Forms::$url . 'assets/js/',
            'use_merge_tags' => array(),
            'opinionated_styles' => Ninja_Forms()->get_setting( 'opinionated_styles' )
        ));

        foreach( Ninja_Forms()->fields as $field ){
            foreach( $field->use_merge_tags() as $merge_tag ){
                $data[ 'use_merge_tags' ][ $merge_tag ][ $field->get_type() ] = $field->get_type();
            }
        }

        wp_localize_script( 'nf-front-end', 'nfFrontEnd', $data );

        do_action( 'ninja_forms_enqueue_scripts', array( 'form_id' => $form_id ) );

        do_action( 'nf_display_enqueue_scripts' );
    }

    protected static function load_template( $file_name = '' )
    {
        if( ! $file_name ) return;

        if( self::is_template_loaded( $file_name ) ) return;

        self::$loaded_templates[] = $file_name;
    }

    public static function output_templates()
    {
        // Build File Path Hierarchy
        $file_paths = apply_filters( 'ninja_forms_field_template_file_paths', array(
            get_template_directory() . '/ninja-forms/templates/',
        ));

        $file_paths[] = Ninja_Forms::$dir . 'includes/Templates/';

        // Search for and Output File Templates
        foreach( self::$loaded_templates as $file_name ) {

            foreach( $file_paths as $path ){

                if( file_exists( $path . "$file_name.html" ) ){
                    echo file_get_contents( $path . "$file_name.html" );
                    break;
                }
            }
        }

        ?>
        <script>
            var post_max_size = '<?php echo WPN_Helper::string_to_bytes( ini_get('post_max_size') ); ?>';
            var upload_max_filesize = '<?php echo WPN_Helper::string_to_bytes( ini_get( 'upload_max_filesize' ) ); ?>';
            var wp_memory_limit = '<?php echo WPN_Helper::string_to_bytes( WP_MEMORY_LIMIT ); ?>';
        </script>
        <?php

        // Action to Output Custom Templates
        do_action( 'ninja_forms_output_templates' );
    }

    /*
     * UTILITY
     */

    protected static function is_template_loaded( $template_name )
    {
        return ( in_array( $template_name, self::$loaded_templates ) ) ? TRUE : FALSE ;
    }

    protected static function form_uses_recaptcha( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( 'recaptcha' == $field->get_setting( 'type' ) ) return true;
        }
        return false;
    }

    protected static function form_uses_datepicker( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( 'date' == $field->get_setting( 'type' ) ) return true;
        }
        return false;
    }

    protected static function form_uses_inputmask( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( $field->get_setting( 'mask' ) ) return true;
        }
        return false;
    }

    protected static function form_uses_rte( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( $field->get_setting( 'textarea_rte' ) ) return true;
        }
        return false;
    }

    protected static function form_uses_textarea_media( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( $field->get_setting( 'textarea_media' ) ) return true;
        }
        return false;
    }

    protected static function form_uses_helptext( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( strip_tags( $field->get_setting( 'help_text' ) ) ) return true;
        }
        return false;
    }

    protected static function form_uses_starrating( $form_id )
    {
        foreach( Ninja_Forms()->form( $form_id )->get_fields() as $field ){
            if( 'starrating' == $field->get_setting( 'type' ) ) return true;
        }
        return false;
    }

} // End Class NF_Display_Render
