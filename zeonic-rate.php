<?php
/**
 * Plugin Name: زئونیک نرخ
 * Description:افزونه بروزرسانی قیمت محصولات بر اساس ارز
 * Version: 1.8.0
 * Author: Zeonic
 * Text Domain: zeonic-rate
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zeonic_Rate_Plugin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 9);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // فیلدهای محصول ساده
        add_action('woocommerce_product_options_pricing', array($this, 'add_custom_product_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_custom_product_fields'));

        // فیلدهای محصولات متغیر
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_custom_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_custom_fields'), 10, 2);

        // ویرایش سریع
        add_action('woocommerce_product_quick_edit_end', array($this, 'add_quick_edit_fields'));
        add_action('woocommerce_product_quick_edit_save', array($this, 'save_quick_edit_fields'));

        // AJAX برای پردازش دسته‌ای
        add_action('wp_ajax_zeonic_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_zeonic_restore_backup', array($this, 'ajax_restore_backup'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    private function get_user_capability() {
        if (current_user_can('manage_woocommerce')) {
            return 'manage_woocommerce';
        }
        if (current_user_can('edit_products')) {
            return 'edit_products';
        }
        return 'manage_options';
    }

    public function add_admin_menu() {
        $capability = $this->get_user_capability();
        add_menu_page(
            'زئونیک نرخ',
            'زئونیک نرخ',
            $capability,
            'zeonic-rate',
            array($this, 'render_admin_page'),
            'dashicons-calculator',
            2
        );
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=zeonic-rate') . '">تنظیمات</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
            wp_enqueue_script('jquery');
            add_action('admin_footer', function() {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#the-list').on('click', '.editinline', function() {
                        var post_id = $(this).closest('tr').attr('id').replace('post-', '');
                        var $meta_box = $('#edit-' + post_id);
                        
                        var enable_dollar = $('#zeonic_enable_dollar_' + post_id).val();
                        var dollar_price = $('#zeonic_dollar_price_' + post_id).val();
                        var dollar_sale = $('#zeonic_dollar_sale_price_' + post_id).val();

                        $meta_box.find('input[name="_zeonic_enable_dollar"]').prop('checked', enable_dollar === 'yes');
                        $meta_box.find('input[name="_zeonic_dollar_price"]').val(dollar_price);
                        $meta_box.find('input[name="_zeonic_dollar_sale_price"]').val(dollar_sale);
                    });
                });
                </script>
                <?php
            });
            return;
        }

        if ($hook !== 'toplevel_page_zeonic-rate') {
            return;
        }

        wp_enqueue_script('jquery');
        
        add_action('admin_footer', function() {
            ?>
            <style>
                .zeonic-modal-overlay {
                    display: none; position: fixed; top: 0; left: 0;
                    width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6);
                    z-index: 99999; justify-content: center; align-items: center;
                }
                .zeonic-modal-box {
                    background: #fff; padding: 25px; border-radius: 8px;
                    max-width: 420px; width: 90%; text-align: center;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                }
                .zeonic-progress-bar {
                    width: 100%; background-color: #f0f0f1; border-radius: 4px;
                    overflow: hidden; margin-top: 10px; display: none; height: 18px;
                }
                .zeonic-progress-fill {
                    width: 0%; height: 100%; background-color: #2271b1;
                    transition: width 0.3s; text-align: center; color: white;
                    font-size: 11px; line-height: 18px;
                }
            </style>

            <div id="zeonic-confirm-modal" class="zeonic-modal-overlay">
                <div class="zeonic-modal-box">
                    <h3 style="margin-top:0;">تأیید بازگردانی بک‌آپ</h3>
                    <p>آیا مطمئن هستید که می‌خواهید قیمت تمام محصولات را به آخرین بک‌آپ ذخیره‌شده بازگردانید؟</p>
                    <div style="margin-top:20px; display:flex; justify-content:center; gap:10px;">
                        <button type="button" id="zeonic-modal-yes" class="button button-primary" style="background:#d63638; border-color:#d63638;">بله، بازگردانی شود</button>
                        <button type="button" id="zeonic-modal-no" class="button button-secondary">خیر</button>
                    </div>
                </div>
            </div>

            <script type="text/javascript">
            jQuery(document).ready(function($) {

                function processBatch(mode, params, page, totalProcessed) {
                    var $status = (mode === 'dollar') ? $('#zeonic-dollar-status') : $('#zeonic-percent-status');
                    var $progress = (mode === 'dollar') ? $('#zeonic-dollar-progress') : $('#zeonic-percent-progress');
                    var $fill = $progress.find('.zeonic-progress-fill');

                    $progress.show();
                    
                    var requestData = $.extend({
                        action: 'zeonic_process_batch',
                        process_mode: mode,
                        page: page,
                        nonce: '<?php echo wp_create_nonce('zeonic_rate_nonce'); ?>'
                    }, params);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: requestData,
                        success: function(response) {
                            if (response.success) {
                                var currentProcessed = totalProcessed + response.data.processed;
                                var total = response.data.total;
                                var percentage = total > 0 ? Math.round((currentProcessed / total) * 100) : 100;

                                $fill.css('width', percentage + '%').text(percentage + '%');
                                $status.html('<span style="color:#007cba;">پردازش ' + currentProcessed + ' از ' + total + ' محصول...</span>');

                                if (!response.data.completed) {
                                    processBatch(mode, params, page + 1, currentProcessed);
                                } else {
                                    $status.html('<span style="color:green;">عملیات با موفقیت انجام شد! به‌روزرسانی ' + currentProcessed + ' محصول تکمیل گردید.</span>');
                                    setTimeout(function() { location.reload(); }, 1500);
                                }
                            } else {
                                $status.html('<span style="color:red;">خطا: ' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            $status.html('<span style="color:red;">خطا در ارتباط با سرور. قطع دسترسی یا محدودیت منابع.</span>');
                        }
                    });
                }

                $('#zeonic-dollar-form').on('submit', function(e) {
                    e.preventDefault();
                    var params = {
                        dollar_rate: $('#zeonic_dollar_rate').val(),
                        round_mode: $('#zeonic_round_mode').val(),
                        round_step: $('#zeonic_round_step').val()
                    };
                    $('#zeonic-dollar-status').html('<span style="color:#007cba;">شروع پشتیبان‌گیری و آمادگی پردازش...</span>');
                    processBatch('dollar', params, 1, 0);
                });

                $('#zeonic-percent-form').on('submit', function(e) {
                    e.preventDefault();
                    var params = {
                        percentage: $('#zeonic_percentage').val(),
                        apply_to: $('#zeonic_apply_to').val(),
                        round_mode: $('#zeonic_round_mode_percent').val(),
                        round_step: $('#zeonic_round_step_percent').val()
                    };
                    $('#zeonic-percent-status').html('<span style="color:#007cba;">شروع پشتیبان‌گیری و آمادگی پردازش...</span>');
                    processBatch('percent', params, 1, 0);
                });

                $('#zeonic-restore-btn').on('click', function(e) {
                    e.preventDefault();
                    $('#zeonic-confirm-modal').css('display', 'flex');
                });

                $('#zeonic-modal-no').on('click', function() {
                    $('#zeonic-confirm-modal').hide();
                });

                $('#zeonic-modal-yes').on('click', function() {
                    $('#zeonic-confirm-modal').hide();
                    var $status = $('#zeonic-backup-status');
                    $status.html('<span style="color:#007cba;">در حال بازگردانی قیمت‌ها از بک‌آپ...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zeonic_restore_backup',
                            nonce: '<?php echo wp_create_nonce('zeonic_rate_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color:green;">' + response.data + '</span>');
                            } else {
                                $status.html('<span style="color:red;">' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            $status.html('<span style="color:red;">خطایی در بازگردانی بک‌آپ رخ داد.</span>');
                        }
                    });
                });

            });
            </script>
            <?php
        });
    }

    public function render_admin_page() {
        $saved_rate = get_option('zeonic_last_dollar_rate', '');
        $last_backup_date = get_option('zeonic_last_backup_date', 'هیچ بک‌آپی ثبت نشده است');
        ?>
        <div class="wrap">
            <h1>تنظیمات زئونیک نرخ</h1>
            <p>مدیریت هوشمند قیمت‌گذاری محصولات بر اساس نرخ دلار، درصد افزایش، گرد کردن قیمت‌ها و نسخه پشتیبان</p>
            <hr />

            <!-- بخش ۱: محاسبه بر اساس دلار -->
            <div style="background:#fff; padding:20px; border:1px solid #ccc; max-width:650px; margin-bottom:20px;">
                <h2>۱. محاسبه بر اساس نرخ دلار</h2>
                <form id="zeonic-dollar-form">
                    <p>
                        <label for="zeonic_dollar_rate"><strong>نرخ دلار (تومان):</strong></label><br>
                        <input type="number" step="any" id="zeonic_dollar_rate" value="<?php echo esc_attr($saved_rate); ?>" required style="width:100%; max-width:320px;">
                    </p>
                    <p>
                        <label for="zeonic_round_mode"><strong>حالت گرد کردن:</strong></label><br>
                        <select id="zeonic_round_mode" style="width:100%; max-width:320px;">
                            <option value="none">بدون گرد کردن</option>
                            <option value="up">گرد کردن به بالا (Ceil)</option>
                            <option value="down">گرد کردن به پایین (Floor)</option>
                        </select>
                    </p>
                    <p>
                        <label for="zeonic_round_step"><strong>پله گرد کردن:</strong></label><br>
                        <select id="zeonic_round_step" style="width:100%; max-width:320px;">
                            <option value="10000">تا ۱۰,۰۰۰ تومان</option>
                            <option value="100000">تا ۱۰۰,۰۰۰ تومان</option>
                            <option value="1000000">تا ۱,۰۰۰,۰۰۰ تومان</option>
                        </select>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">اعمال نرخ دلار و به‌روزرسانی قیمت‌ها</button>
                    </p>
                    <div id="zeonic-dollar-progress" class="zeonic-progress-bar">
                        <div class="zeonic-progress-fill">0%</div>
                    </div>
                    <div id="zeonic-dollar-status" style="margin-top:10px;"></div>
                </form>
            </div>

            <!-- بخش ۲: افزایش درصدی -->
            <div style="background:#fff; padding:20px; border:1px solid #ccc; max-width:650px; margin-bottom:20px;">
                <h2>۲. افزایش درصدی قیمت‌ها</h2>
                <form id="zeonic-percent-form">
                    <p>
                        <label for="zeonic_percentage"><strong>درصد افزایش (مثلاً ۱۰ یا -۵ برای کاهش):</strong></label><br>
                        <input type="number" step="any" id="zeonic_percentage" required style="width:100%; max-width:320px;">
                    </p>
                    <p>
                        <label for="zeonic_apply_to"><strong>اعمال روی:</strong></label><br>
                        <select id="zeonic_apply_to" style="width:100%; max-width:320px;">
                            <option value="all">همه محصولات</option>
                            <option value="dollar">محصولات دلاری</option>
                            <option value="toman">محصولات تومانی</option>
                        </select>
                    </p>
                    <p>
                        <label for="zeonic_round_mode_percent"><strong>حالت گرد کردن:</strong></label><br>
                        <select id="zeonic_round_mode_percent" style="width:100%; max-width:320px;">
                            <option value="none">بدون گرد کردن</option>
                            <option value="up">گرد کردن به بالا (Ceil)</option>
                            <option value="down">گرد کردن به پایین (Floor)</option>
                        </select>
                    </p>
                    <p>
                        <label for="zeonic_round_step_percent"><strong>پله گرد کردن:</strong></label><br>
                        <select id="zeonic_round_step_percent" style="width:100%; max-width:320px;">
                            <option value="10000">تا ۱۰,۰۰۰ تومان</option>
                            <option value="100000">تا ۱۰۰,۰۰۰ تومان</option>
                            <option value="1000000">تا ۱,۰۰۰,۰۰۰ تومان</option>
                        </select>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">اعمال افزایش درصدی</button>
                    </p>
                    <div id="zeonic-percent-progress" class="zeonic-progress-bar">
                        <div class="zeonic-progress-fill">0%</div>
                    </div>
                    <div id="zeonic-percent-status" style="margin-top:10px;"></div>
                </form>
            </div>

            <!-- بخش ۳: بازگردانی بک‌آپ -->
            <div style="background:#fff; padding:20px; border:1px solid #ccc; max-width:650px; border-right: 4px solid #d63638;">
                <h2>۳. بازگردانی قیمت‌های قبلی (بک‌آپ)</h2>
                <p>آخرین بک‌آپ ثبت شده در تاریخ: <strong><?php echo esc_html($last_backup_date); ?></strong></p>
                <p>
                    <button type="button" id="zeonic-restore-btn" class="button" style="background:#d63638; color:#fff; border-color:#d63638;">بازگردانی آخرین بک‌آپ قیمت‌ها</button>
                </p>
                <div id="zeonic-backup-status" style="margin-top:10px;"></div>
            </div>
        </div>
        <?php
    }

    private function apply_rounding($price, $mode, $step) {
        $price = floatval($price);
        $step  = floatval($step);

        if ($price <= 0 || $mode === 'none' || $step <= 0) {
            return round($price);
        }

        if ($mode === 'up') {
            return ceil($price / $step) * $step;
        } elseif ($mode === 'down') {
            return floor($price / $step) * $step;
        }

        return round($price);
    }

    public function add_custom_product_fields() {
        echo '<div class="options_group zeonic_dollar_options">';
        woocommerce_wp_checkbox(array(
            'id'          => '_zeonic_enable_dollar',
            'label'       => 'محاسبه دلاری قیمت',
            'description' => 'فعالسازی قیمت‌گذاری دلاری برای این محصول',
        ));
        woocommerce_wp_text_input(array(
            'id'                => '_zeonic_dollar_price',
            'label'             => 'قیمت اصلی ($)',
            'type'              => 'number',
            'custom_attributes' => array('step' => '0.01', 'min' => '0')
        ));
        woocommerce_wp_text_input(array(
            'id'                => '_zeonic_dollar_sale_price',
            'label'             => 'قیمت فروش ویژه ($)',
            'type'              => 'number',
            'custom_attributes' => array('step' => '0.01', 'min' => '0')
        ));
        echo '</div>';
    }

    public function save_custom_product_fields($post_id) {
        $enable_dollar = isset($_POST['_zeonic_enable_dollar']) ? 'yes' : 'no';
        update_post_meta($post_id, '_zeonic_enable_dollar', $enable_dollar);

        if (isset($_POST['_zeonic_dollar_price'])) {
            $val = $_POST['_zeonic_dollar_price'] !== '' ? floatval($_POST['_zeonic_dollar_price']) : '';
            update_post_meta($post_id, '_zeonic_dollar_price', $val);
        }
        if (isset($_POST['_zeonic_dollar_sale_price'])) {
            $val = $_POST['_zeonic_dollar_sale_price'] !== '' ? floatval($_POST['_zeonic_dollar_sale_price']) : '';
            update_post_meta($post_id, '_zeonic_dollar_sale_price', $val);
        }

        if ($enable_dollar === 'yes') {
            $dollar_rate = get_option('zeonic_last_dollar_rate', 0);
            if ($dollar_rate > 0) {
                $this->update_single_product_dollar_price($post_id, $dollar_rate, 'none', 10000);
            }
        }
    }

    public function add_variation_custom_fields($loop, $variation_data, $variation) {
        echo '<div class="options_group" style="padding: 10px; border-top: 1px solid #eee;">';
        echo '<strong>تنظیمات قیمت دلاری زئونیک:</strong>';
        
        woocommerce_wp_checkbox(array(
            'id'            => '_zeonic_enable_dollar_' . $variation->ID,
            'name'          => '_zeonic_enable_dollar[' . $loop . ']',
            'label'         => 'محاسبه دلاری قیمت',
            'value'         => get_post_meta($variation->ID, '_zeonic_enable_dollar', true),
        ));

        woocommerce_wp_text_input(array(
            'id'                => '_zeonic_dollar_price_' . $variation->ID,
            'name'              => '_zeonic_dollar_price[' . $loop . ']',
            'label'             => 'قیمت اصلی ($)',
            'value'             => get_post_meta($variation->ID, '_zeonic_dollar_price', true),
            'type'              => 'number',
            'custom_attributes' => array('step' => '0.01', 'min' => '0')
        ));

        woocommerce_wp_text_input(array(
            'id'                => '_zeonic_dollar_sale_price_' . $variation->ID,
            'name'              => '_zeonic_dollar_sale_price[' . $loop . ']',
            'label'             => 'قیمت فروش ویژه ($)',
            'value'             => get_post_meta($variation->ID, '_zeonic_dollar_sale_price', true),
            'type'              => 'number',
            'custom_attributes' => array('step' => '0.01', 'min' => '0')
        ));

        echo '</div>';
    }

    public function save_variation_custom_fields($variation_id, $i) {
        $enable_dollar = isset($_POST['_zeonic_enable_dollar'][$i]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_zeonic_enable_dollar', $enable_dollar);

        if (isset($_POST['_zeonic_dollar_price'][$i])) {
            $val = $_POST['_zeonic_dollar_price'][$i] !== '' ? floatval($_POST['_zeonic_dollar_price'][$i]) : '';
            update_post_meta($variation_id, '_zeonic_dollar_price', $val);
        }
        if (isset($_POST['_zeonic_dollar_sale_price'][$i])) {
            $val = $_POST['_zeonic_dollar_sale_price'][$i] !== '' ? floatval($_POST['_zeonic_dollar_sale_price'][$i]) : '';
            update_post_meta($variation_id, '_zeonic_dollar_sale_price', $val);
        }

        if ($enable_dollar === 'yes') {
            $dollar_rate = get_option('zeonic_last_dollar_rate', 0);
            if ($dollar_rate > 0) {
                $this->update_single_product_dollar_price($variation_id, $dollar_rate, 'none', 10000);
            }
        }
    }

    public function add_quick_edit_fields() {
        ?>
        <br class="clear" />
        <strong class="title">تنظیمات زئونیک نرخ</strong>
        <div class="inline-edit-col">
            <label class="alignleft">
                <input type="checkbox" name="_zeonic_enable_dollar" value="yes">
                <span class="checkbox-title">محاسبه دلاری قیمت</span>
            </label>
            <br class="clear" />
            <label class="alignleft">
                <span class="title">قیمت دلاری ($)</span>
                <span class="input-text-wrap">
                    <input type="number" step="0.01" min="0" name="_zeonic_dollar_price" class="text" value="">
                </span>
            </label>
            <label class="alignleft">
                <span class="title">تخفیف دلاری ($)</span>
                <span class="input-text-wrap">
                    <input type="number" step="0.01" min="0" name="_zeonic_dollar_sale_price" class="text" value="">
                </span>
            </label>
        </div>
        <?php
    }

    public function save_quick_edit_fields($product) {
        $post_id = $product->get_id();
        $enable_dollar = isset($_POST['_zeonic_enable_dollar']) ? 'yes' : 'no';
        update_post_meta($post_id, '_zeonic_enable_dollar', $enable_dollar);

        if (isset($_POST['_zeonic_dollar_price'])) {
            $val = $_POST['_zeonic_dollar_price'] !== '' ? floatval($_POST['_zeonic_dollar_price']) : '';
            update_post_meta($post_id, '_zeonic_dollar_price', $val);
        }
        if (isset($_POST['_zeonic_dollar_sale_price'])) {
            $val = $_POST['_zeonic_dollar_sale_price'] !== '' ? floatval($_POST['_zeonic_dollar_sale_price']) : '';
            update_post_meta($post_id, '_zeonic_dollar_sale_price', $val);
        }

        if ($enable_dollar === 'yes') {
            $dollar_rate = get_option('zeonic_last_dollar_rate', 0);
            if ($dollar_rate > 0) {
                $this->update_single_product_dollar_price($post_id, $dollar_rate, 'none', 10000);
            }
        }
    }

    private function create_price_backup() {
        $args = array(
            'post_type'      => array('product', 'product_variation'),
            'posts_per_page' => -1,
            'fields'         => 'ids'
        );

        $product_ids = get_posts($args);
        $backup_data = array();

        foreach ($product_ids as $p_id) {
            $product = wc_get_product($p_id);
            if ($product) {
                $backup_data[$p_id] = array(
                    'regular_price' => $product->get_regular_price(),
                    'sale_price'    => $product->get_sale_price()
                );
            }
        }

        update_option('zeonic_price_backup', $backup_data);
        update_option('zeonic_last_backup_date', date_i18n('Y-m-d H:i:s'));
    }

    public function ajax_process_batch() {
        check_ajax_referer('zeonic_rate_nonce', 'nonce');

        if (!current_user_can($this->get_user_capability())) {
            wp_send_json_error('عدم دسترسی');
        }

        $process_mode = isset($_POST['process_mode']) ? sanitize_text_field($_POST['process_mode']) : '';
        $page         = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page     = 50; // پردازش ۵۰ محصول در هر درخواست

        if ($page === 1) {
            $this->create_price_backup();
        }

        $args = array(
            'post_type'      => array('product', 'product_variation'),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids'
        );

        if ($process_mode === 'dollar') {
            $dollar_rate = floatval($_POST['dollar_rate']);
            $round_mode  = sanitize_text_field($_POST['round_mode']);
            $round_step  = floatval($_POST['round_step']);

            if ($page === 1) {
                update_option('zeonic_last_dollar_rate', $dollar_rate);
            }

            $args['meta_query'] = array(
                array(
                    'key'     => '_zeonic_enable_dollar',
                    'value'   => 'yes',
                    'compare' => '='
                )
            );

            $query = new WP_Query($args);
            $total_products = $query->found_posts;

            foreach ($query->posts as $product_id) {
                $this->update_single_product_dollar_price($product_id, $dollar_rate, $round_mode, $round_step);
            }

        } elseif ($process_mode === 'percent') {
            $percentage = floatval($_POST['percentage']);
            $apply_to   = sanitize_text_field($_POST['apply_to']);
            $round_mode = sanitize_text_field($_POST['round_mode']);
            $round_step = floatval($_POST['round_step']);
            $multiplier = 1 + ($percentage / 100);

            if ($apply_to === 'toman') {
                $args['meta_query'] = array(
                    'relation' => 'OR',
                    array('key' => '_zeonic_enable_dollar', 'compare' => 'NOT EXISTS'),
                    array('key' => '_zeonic_enable_dollar', 'value' => 'yes', 'compare' => '!=')
                );
            } elseif ($apply_to === 'dollar') {
                $args['meta_query'] = array(
                    array('key' => '_zeonic_enable_dollar', 'value' => 'yes', 'compare' => '=')
                );
            }

            $query = new WP_Query($args);
            $total_products = $query->found_posts;

            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;

                $regular_price = $product->get_regular_price();
                $sale_price    = $product->get_sale_price();

                if ($regular_price !== '') {
                    $raw_regular = $regular_price * $multiplier;
                    $product->set_regular_price($this->apply_rounding($raw_regular, $round_mode, $round_step));
                }
                if ($sale_price !== '') {
                    $raw_sale = $sale_price * $multiplier;
                    $product->set_sale_price($this->apply_rounding($raw_sale, $round_mode, $round_step));
                }
                $product->save();
            }
        } else {
            wp_send_json_error('حالت نامعتبر');
        }

        $processed_in_this_batch = count($query->posts);
        $completed = ($page * $per_page) >= $total_products;

        if ($completed) {
            $this->purge_wp_rocket_cache();
        }

        wp_send_json_success(array(
            'processed' => $processed_in_this_batch,
            'total'     => $total_products,
            'completed' => $completed
        ));
    }

    public function ajax_restore_backup() {
        check_ajax_referer('zeonic_rate_nonce', 'nonce');

        if (!current_user_can($this->get_user_capability())) {
            wp_send_json_error('شما دسترسی لازم را ندارید.');
        }

        $backup_data = get_option('zeonic_price_backup', array());

        if (empty($backup_data)) {
            wp_send_json_error('هیچ بک‌آپی برای بازگردانی یافت نشد.');
        }

        $restored_count = 0;

        foreach ($backup_data as $product_id => $prices) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_regular_price($prices['regular_price']);
                $product->set_sale_price($prices['sale_price']);
                $product->save();
                $restored_count++;
            }
        }

        $this->purge_wp_rocket_cache();
        wp_send_json_success("قیمت {$restored_count} محصول با موفقیت به حالت قبل بازگردانده شد.");
    }

    private function update_single_product_dollar_price($product_id, $dollar_rate, $round_mode = 'none', $round_step = 10000) {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $dollar_price      = get_post_meta($product_id, '_zeonic_dollar_price', true);
        $dollar_sale_price = get_post_meta($product_id, '_zeonic_dollar_sale_price', true);

        if ($dollar_price !== '') {
            $calc_regular = floatval($dollar_price) * $dollar_rate;
            $final_regular = $this->apply_rounding($calc_regular, $round_mode, $round_step);
            $product->set_regular_price($final_regular);
        }

        if ($dollar_sale_price !== '') {
            $calc_sale = floatval($dollar_sale_price) * $dollar_rate;
            $final_sale = $this->apply_rounding($calc_sale, $round_mode, $round_step);
            $product->set_sale_price($final_sale);
        } else {
            $product->set_sale_price('');
        }

        $product->save();
    }

    private function purge_wp_rocket_cache() {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        wc_delete_product_transients();
    }
}

new Zeonic_Rate_Plugin();
