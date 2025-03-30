<?php

/**
 * Plugin Name: WooCommerce Purchase Price & Profit
 * Plugin URI: https://tudominio.com
 * Description: Registra el precio de compra de productos y calcula las ganancias netas en WooCommerce para AutoAzul y ZZContigo
 * Version: 1.0.0
 * Author: Juan David Franco
 * Author URI: https://tudominio.com
 * Text Domain: wc-purchase-price
 * Domain Path: /languages
 * WC requires at least: 4.0.0
 * WC tested up to: 8.5.0
 * Requires PHP: 7.0
 * 
 * WooCommerce Features:
 * WC_HPOS_COMPATIBLE: yes
 * WC_EMAIL_COMPATIBLE: yes
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('WPINC')) {
    die;
}

// Definir constantes
define('WC_PURCHASE_PRICE_VERSION', '1.0.0');
define('WC_PURCHASE_PRICE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PURCHASE_PRICE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Declarar compatibilidad con HPOS (High-Performance Order Storage)
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
    }
});

/**
 * Clase principal del plugin
 */
class WC_Purchase_Price
{

    /**
     * Constructor
     */
    public function __construct()
    {
        // Verificar si WooCommerce está activo
        add_action('admin_init', [$this, 'check_woocommerce_active']);

        // Añadir campo de precio de compra en la página de producto
        add_action('woocommerce_product_options_pricing', [$this, 'add_purchase_price_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_purchase_price_field']);

        // Añadir campo para productos variables
        add_action('woocommerce_variation_options_pricing', [$this, 'add_variation_purchase_price_field'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_purchase_price_field'], 10, 2);

        // Añadir columna de ganancias en la lista de productos
        add_filter('manage_edit-product_columns', [$this, 'add_profit_column']);
        add_action('manage_product_posts_custom_column', [$this, 'populate_profit_column'], 10, 2);

        // Añadir página de informes
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Añadir scripts y estilos en el admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Añadir AJAX para exportar CSV
        add_action('wp_ajax_export_profit_report', [$this, 'export_profit_report']);
        add_action('wp_ajax_export_inventory_report', [$this, 'export_inventory_report']);
    }

    /**
     * Verificar si WooCommerce está activo
     */
    public function check_woocommerce_active()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
?>
                <div class="error">
                    <p><?php _e('El plugin "WooCommerce Purchase Price & Profit" requiere que WooCommerce esté instalado y activado.', 'wc-purchase-price'); ?>
                    </p>
                </div>
        <?php
            });
            deactivate_plugins(plugin_basename(__FILE__));
        }
    }

    /**
     * Añadir campo de precio de compra en productos simples
     */
    public function add_purchase_price_field()
    {
        woocommerce_wp_text_input([
            'id' => '_purchase_price',
            'label' => __('Precio de compra (€)', 'wc-purchase-price'),
            'desc_tip' => true,
            'description' => __('Ingresa el precio al que compraste este producto.', 'wc-purchase-price'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => 'any',
                'min' => '0'
            ]
        ]);
    }

    /**
     * Guardar el precio de compra para productos simples
     */
    public function save_purchase_price_field($post_id)
    {
        if (isset($_POST['_purchase_price'])) {
            update_post_meta($post_id, '_purchase_price', wc_clean(wp_unslash($_POST['_purchase_price'])));
        }
    }

    /**
     * Añadir campo de precio de compra en productos variables
     */
    public function add_variation_purchase_price_field($loop, $variation_data, $variation)
    {
        woocommerce_wp_text_input([
            'id' => '_purchase_price[' . $variation->ID . ']',
            'label' => __('Precio de compra (€)', 'wc-purchase-price'),
            'desc_tip' => true,
            'description' => __('Ingresa el precio al que compraste esta variación.', 'wc-purchase-price'),
            'value' => get_post_meta($variation->ID, '_purchase_price', true),
            'wrapper_class' => 'form-row form-row-first',
            'type' => 'number',
            'custom_attributes' => [
                'step' => 'any',
                'min' => '0'
            ]
        ]);
    }

    /**
     * Guardar el precio de compra para productos variables
     */
    public function save_variation_purchase_price_field($variation_id, $i)
    {
        if (isset($_POST['_purchase_price'][$variation_id])) {
            update_post_meta($variation_id, '_purchase_price', wc_clean(wp_unslash($_POST['_purchase_price'][$variation_id])));
        }
    }

    /**
     * Añadir columna de precio de compra y ganancia en la lista de productos
     */
    public function add_profit_column($columns)
    {
        $new_columns = [];

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;

            // Añadir después de la columna de precio
            if ($key === 'price') {
                $new_columns['purchase_price'] = __('Precio de compra', 'wc-purchase-price');
                $new_columns['profit'] = __('Ganancia', 'wc-purchase-price');
            }
        }

        return $new_columns;
    }

    /**
     * Mostrar el precio de compra y la ganancia en las columnas
     */
    public function populate_profit_column($column, $post_id)
    {
        if ($column === 'purchase_price') {
            $product = wc_get_product($post_id);

            if (!$product) {
                return;
            }

            // Para productos simples
            if ($product->is_type('simple')) {
                $purchase_price = get_post_meta($post_id, '_purchase_price', true);

                if ($purchase_price) {
                    echo wc_price($purchase_price);
                } else {
                    echo '<span class="na">–</span>';
                }
            }
            // Para productos variables
            elseif ($product->is_type('variable')) {
                $variations = $product->get_available_variations();
                $purchase_prices = [];

                foreach ($variations as $variation) {
                    $variation_id = $variation['variation_id'];
                    $purchase_price = get_post_meta($variation_id, '_purchase_price', true);

                    if ($purchase_price) {
                        $purchase_prices[] = floatval($purchase_price);
                    }
                }

                if (!empty($purchase_prices)) {
                    $min_price = min($purchase_prices);
                    $max_price = max($purchase_prices);

                    if ($min_price === $max_price) {
                        echo wc_price($min_price);
                    } else {
                        echo wc_price($min_price) . ' - ' . wc_price($max_price);
                    }
                } else {
                    echo '<span class="na">–</span>';
                }
            }
        } elseif ($column === 'profit') {
            $product = wc_get_product($post_id);

            if (!$product) {
                return;
            }

            // Para productos simples
            if ($product->is_type('simple')) {
                $regular_price = $product->get_regular_price();
                $sale_price = $product->get_sale_price();
                $purchase_price = get_post_meta($post_id, '_purchase_price', true);

                $price = $sale_price ? $sale_price : $regular_price;

                if ($purchase_price && $price) {
                    $profit = floatval($price) - floatval($purchase_price);
                    $profit_percentage = ($price > 0) ? ($profit / floatval($price) * 100) : 0;

                    echo wc_price($profit) . ' (' . number_format($profit_percentage, 2) . '%)';
                } else {
                    echo '<span class="na">–</span>';
                }
            }
            // Para productos variables
            elseif ($product->is_type('variable')) {
                $variations = $product->get_available_variations();
                $profits = [];

                foreach ($variations as $variation) {
                    $variation_id = $variation['variation_id'];
                    $variation_obj = wc_get_product($variation_id);

                    $price = $variation_obj->get_price();
                    $purchase_price = get_post_meta($variation_id, '_purchase_price', true);

                    if ($purchase_price && $price) {
                        $profits[] = floatval($price) - floatval($purchase_price);
                    }
                }

                if (!empty($profits)) {
                    $min_profit = min($profits);
                    $max_profit = max($profits);

                    if ($min_profit === $max_profit) {
                        echo wc_price($min_profit);
                    } else {
                        echo wc_price($min_profit) . ' - ' . wc_price($max_profit);
                    }
                } else {
                    echo '<span class="na">–</span>';
                }
            }
        }
    }

    /**
     * Añadir páginas de informes al menú
     */
    public function add_admin_menu()
    {
        // Página de informe de ganancias por ventas
        add_submenu_page(
            'woocommerce',
            __('Informe de Ganancias', 'wc-purchase-price'),
            __('Informe de Ganancias', 'wc-purchase-price'),
            'manage_woocommerce',
            'wc-profit-report',
            [$this, 'render_profit_report_page']
        );

        // Página de informe de inventario y capital
        add_submenu_page(
            'woocommerce',
            __('Capital e Inventario', 'wc-purchase-price'),
            __('Capital e Inventario', 'wc-purchase-price'),
            'manage_woocommerce',
            'wc-inventory-capital',
            [$this, 'render_inventory_capital_page']
        );
    }

    /**
     * Renderizar la página de informe de ganancias
     */
    public function render_profit_report_page()
    {
        // Opciones de fecha
        $current_month = date('m');
        $current_year = date('Y');

        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        // Conseguir todos los pedidos en el rango de fechas
        $args = [
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'date_created' => $start_date . '...' . $end_date,
            'type' => 'shop_order', // Especificar tipo para compatibilidad con HPOS
        ];

        $orders = wc_get_orders($args);

        // Procesar los datos para el informe
        $products_data = [];
        $total_revenue = 0;
        $total_cost = 0;
        $total_profit = 0;

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $actual_id = $variation_id ? $variation_id : $product_id;
                $product = $item->get_product();

                if (!$product) {
                    continue;
                }

                $quantity = $item->get_quantity();
                $total = $item->get_total();
                $purchase_price = get_post_meta($actual_id, '_purchase_price', true);

                if (!$purchase_price) {
                    $purchase_price = 0;
                }

                $purchase_cost = floatval($purchase_price) * $quantity;
                $profit = $total - $purchase_cost;

                // Combinar los datos por producto
                if (!isset($products_data[$actual_id])) {
                    $products_data[$actual_id] = [
                        'id' => $actual_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'quantity' => 0,
                        'revenue' => 0,
                        'cost' => 0,
                        'profit' => 0
                    ];
                }

                $products_data[$actual_id]['quantity'] += $quantity;
                $products_data[$actual_id]['revenue'] += $total;
                $products_data[$actual_id]['cost'] += $purchase_cost;
                $products_data[$actual_id]['profit'] += $profit;

                // Calcular totales
                $total_revenue += $total;
                $total_cost += $purchase_cost;
                $total_profit += $profit;
            }
        }

        // Ordenar por ganancia (mayor a menor)
        usort($products_data, function ($a, $b) {
            return $b['profit'] <=> $a['profit'];
        });

        // Renderizar la página
        ?>
        <div class="wrap">
            <h1><?php _e('Informe de Ganancias', 'wc-purchase-price'); ?></h1>

            <div class="tablenav top">
                <form method="get">
                    <input type="hidden" name="page" value="wc-profit-report">

                    <div class="alignleft actions">
                        <label for="start_date"
                            style="display: inline-block; margin-right: 5px;"><?php _e('Desde:', 'wc-purchase-price'); ?></label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>"
                            class="regular-text">

                        <label for="end_date"
                            style="display: inline-block; margin: 0 5px 0 15px;"><?php _e('Hasta:', 'wc-purchase-price'); ?></label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>"
                            class="regular-text">

                        <button type="submit" class="button action"><?php _e('Filtrar', 'wc-purchase-price'); ?></button>
                    </div>
                </form>

                <div class="alignright">
                    <button id="export-profit-report"
                        class="button button-primary"><?php _e('Exportar CSV', 'wc-purchase-price'); ?></button>
                </div>
                <br class="clear">
            </div>

            <div class="profit-summary"
                style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4;">
                <h2><?php _e('Resumen', 'wc-purchase-price'); ?></h2>
                <div class="summary-data" style="display: flex; justify-content: space-between; max-width: 600px;">
                    <div class="summary-item">
                        <h3><?php _e('Ingresos totales', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($total_revenue); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Costos totales', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($total_cost); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Ganancia neta', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($total_profit); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Margen', 'wc-purchase-price'); ?></h3>
                        <p class="amount">
                            <?php echo ($total_revenue > 0) ? number_format(($total_profit / $total_revenue * 100), 2) . '%' : '0%'; ?>
                        </p>
                    </div>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Producto', 'wc-purchase-price'); ?></th>
                        <th><?php _e('SKU', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Cantidad', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Ingresos', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Costo', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Ganancia', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Margen', 'wc-purchase-price'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products_data)) : ?>
                        <tr>
                            <td colspan="7">
                                <?php _e('No hay datos disponibles para el período seleccionado.', 'wc-purchase-price'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($products_data as $product_data) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_post_link($product_data['id']); ?>">
                                        <?php echo esc_html($product_data['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($product_data['sku']); ?></td>
                                <td><?php echo esc_html($product_data['quantity']); ?></td>
                                <td><?php echo wc_price($product_data['revenue']); ?></td>
                                <td><?php echo wc_price($product_data['cost']); ?></td>
                                <td><?php echo wc_price($product_data['profit']); ?></td>
                                <td>
                                    <?php
                                    $margin = ($product_data['revenue'] > 0) ? ($product_data['profit'] / $product_data['revenue'] * 100) : 0;
                                    echo number_format($margin, 2) . '%';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    /**
     * Añadir scripts y estilos al admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Solo cargar en las páginas de nuestros informes
        if ('woocommerce_page_wc-profit-report' !== $hook && 'woocommerce_page_wc-inventory-capital' !== $hook) {
            return;
        }

        wp_enqueue_script('wc-purchase-price-admin-js', WC_PURCHASE_PRICE_PLUGIN_URL . 'js/admin.js', ['jquery'], WC_PURCHASE_PRICE_VERSION, true);

        wp_localize_script('wc-purchase-price-admin-js', 'wc_purchase_price', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('export_profit_report_nonce'),
            'start_date' => isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01'),
            'end_date' => isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d'),
        ]);

        wp_enqueue_style('wc-purchase-price-admin-css', WC_PURCHASE_PRICE_PLUGIN_URL . 'css/admin.css', [], WC_PURCHASE_PRICE_VERSION);
    }

    /**
     * Renderizar la página de informe de inventario y capital
     */
    public function render_inventory_capital_page()
    {
        // Calcular capital invertido y ganancias potenciales
        $inventory_data = $this->calculate_inventory_capital();

        // Renderizar la página
    ?>
        <div class="wrap">
            <h1><?php _e('Informe de Capital e Inventario', 'wc-purchase-price'); ?></h1>

            <div class="capital-summary"
                style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4;">
                <h2><?php _e('Resumen de Capital', 'wc-purchase-price'); ?></h2>
                <div class="summary-data" style="display: flex; justify-content: space-between; max-width: 800px;">
                    <div class="summary-item">
                        <h3><?php _e('Capital Invertido Total', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($inventory_data['total_investment']); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Valor de Venta Total', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($inventory_data['total_value']); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Ganancia Potencial', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo wc_price($inventory_data['total_profit']); ?></p>
                    </div>
                    <div class="summary-item">
                        <h3><?php _e('Margen Promedio', 'wc-purchase-price'); ?></h3>
                        <p class="amount"><?php echo number_format($inventory_data['average_margin'], 2) . '%'; ?></p>
                    </div>
                </div>
            </div>

            <div class="inventory-actions" style="margin-bottom: 20px;">
                <button id="export-inventory-report"
                    class="button button-primary"><?php _e('Exportar CSV', 'wc-purchase-price'); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Producto', 'wc-purchase-price'); ?></th>
                        <th><?php _e('SKU', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Stock', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Precio de Compra', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Precio de Venta', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Inversión', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Valor', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Ganancia Potencial', 'wc-purchase-price'); ?></th>
                        <th><?php _e('Margen', 'wc-purchase-price'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventory_data['products'])) : ?>
                        <tr>
                            <td colspan="9">
                                <?php _e('No hay productos con datos de precio de compra y stock.', 'wc-purchase-price'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($inventory_data['products'] as $product) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_post_link($product['id']); ?>">
                                        <?php echo esc_html($product['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($product['sku']); ?></td>
                                <td><?php echo esc_html($product['stock']); ?></td>
                                <td><?php echo wc_price($product['purchase_price']); ?></td>
                                <td><?php echo wc_price($product['sale_price']); ?></td>
                                <td><?php echo wc_price($product['investment']); ?></td>
                                <td><?php echo wc_price($product['value']); ?></td>
                                <td><?php echo wc_price($product['profit']); ?></td>
                                <td><?php echo number_format($product['margin'], 2) . '%'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#export-inventory-report').on('click', function() {
                    var data = {
                        action: 'export_inventory_report',
                        nonce: wc_purchase_price.nonce
                    };

                    // Redirigir para la descarga
                    var url = wc_purchase_price.ajax_url + '?' + $.param(data);
                    window.location.href = url;
                });
            });
        </script>
<?php
    }

    /**
     * Calcular el capital invertido y las ganancias potenciales del inventario
     */
    public function calculate_inventory_capital()
    {
        $products_data = [];
        $total_investment = 0;
        $total_value = 0;
        $total_profit = 0;
        $margins = [];

        // Obtener todos los productos publicados
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $product_ids = get_posts($args);

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            // Para productos simples
            if ($product->is_type('simple')) {
                $stock = $product->get_stock_quantity();

                // Solo procesar productos con stock
                if ($stock === null || $stock <= 0) {
                    continue;
                }

                $purchase_price = get_post_meta($product_id, '_purchase_price', true);

                // Solo procesar productos con precio de compra
                if (!$purchase_price) {
                    continue;
                }

                $sale_price = $product->get_price();

                if (!$sale_price) {
                    continue;
                }

                $investment = floatval($purchase_price) * $stock;
                $value = floatval($sale_price) * $stock;
                $profit = $value - $investment;
                $margin = ($value > 0) ? ($profit / $value * 100) : 0;

                $products_data[] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'stock' => $stock,
                    'purchase_price' => floatval($purchase_price),
                    'sale_price' => floatval($sale_price),
                    'investment' => $investment,
                    'value' => $value,
                    'profit' => $profit,
                    'margin' => $margin
                ];

                $total_investment += $investment;
                $total_value += $value;
                $total_profit += $profit;
                $margins[] = $margin;
            }
            // Para productos variables
            elseif ($product->is_type('variable')) {
                $variations = $product->get_available_variations();

                foreach ($variations as $variation) {
                    $variation_id = $variation['variation_id'];
                    $variation_obj = wc_get_product($variation_id);

                    if (!$variation_obj) {
                        continue;
                    }

                    $stock = $variation_obj->get_stock_quantity();

                    // Solo procesar variaciones con stock
                    if ($stock === null || $stock <= 0) {
                        continue;
                    }

                    $purchase_price = get_post_meta($variation_id, '_purchase_price', true);

                    // Solo procesar variaciones con precio de compra
                    if (!$purchase_price) {
                        continue;
                    }

                    $sale_price = $variation_obj->get_price();

                    if (!$sale_price) {
                        continue;
                    }

                    $investment = floatval($purchase_price) * $stock;
                    $value = floatval($sale_price) * $stock;
                    $profit = $value - $investment;
                    $margin = ($value > 0) ? ($profit / $value * 100) : 0;

                    $variation_name = $product->get_name();
                    $attributes_string = [];

                    foreach ($variation['attributes'] as $attr_name => $attr_value) {
                        $taxonomy = str_replace('attribute_', '', $attr_name);
                        $term = get_term_by('slug', $attr_value, $taxonomy);
                        $attr_label = wc_attribute_label($taxonomy);
                        $attr_value_label = $term ? $term->name : $attr_value;
                        $attributes_string[] = $attr_label . ': ' . $attr_value_label;
                    }

                    if (!empty($attributes_string)) {
                        $variation_name .= ' (' . implode(', ', $attributes_string) . ')';
                    }

                    $products_data[] = [
                        'id' => $variation_id,
                        'name' => $variation_name,
                        'sku' => $variation_obj->get_sku(),
                        'stock' => $stock,
                        'purchase_price' => floatval($purchase_price),
                        'sale_price' => floatval($sale_price),
                        'investment' => $investment,
                        'value' => $value,
                        'profit' => $profit,
                        'margin' => $margin
                    ];

                    $total_investment += $investment;
                    $total_value += $value;
                    $total_profit += $profit;
                    $margins[] = $margin;
                }
            }
        }

        // Ordenar por inversión (mayor a menor)
        usort($products_data, function ($a, $b) {
            return $b['investment'] <=> $a['investment'];
        });

        // Calcular margen promedio
        $average_margin = !empty($margins) ? array_sum($margins) / count($margins) : 0;

        return [
            'products' => $products_data,
            'total_investment' => $total_investment,
            'total_value' => $total_value,
            'total_profit' => $total_profit,
            'average_margin' => $average_margin
        ];
    }

    /**
     * AJAX para exportar informe de inventario y capital
     */
    public function export_inventory_report()
    {
        // Verificar nonce
        check_ajax_referer('export_profit_report_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes para realizar esta acción.', 'wc-purchase-price'));
        }

        // Calcular datos de inventario
        $inventory_data = $this->calculate_inventory_capital();

        // Configurar cabeceras para descargar el CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=informe-capital-inventario-' . date('Y-m-d') . '.csv');

        // Abrir el output
        $output = fopen('php://output', 'w');

        // Añadir BOM UTF-8 para compatibilidad con Excel
        fputs($output, "\xEF\xBB\xBF");

        // Cabeceras CSV
        fputcsv($output, [
            __('ID', 'wc-purchase-price'),
            __('Producto', 'wc-purchase-price'),
            __('SKU', 'wc-purchase-price'),
            __('Stock', 'wc-purchase-price'),
            __('Precio de Compra', 'wc-purchase-price'),
            __('Precio de Venta', 'wc-purchase-price'),
            __('Inversión', 'wc-purchase-price'),
            __('Valor', 'wc-purchase-price'),
            __('Ganancia Potencial', 'wc-purchase-price'),
            __('Margen %', 'wc-purchase-price')
        ]);

        // Datos CSV
        foreach ($inventory_data['products'] as $product) {
            fputcsv($output, [
                $product['id'],
                $product['name'],
                $product['sku'],
                $product['stock'],
                number_format($product['purchase_price'], 2, '.', ''),
                number_format($product['sale_price'], 2, '.', ''),
                number_format($product['investment'], 2, '.', ''),
                number_format($product['value'], 2, '.', ''),
                number_format($product['profit'], 2, '.', ''),
                number_format($product['margin'], 2, '.', '')
            ]);
        }

        // Fila de totales
        fputcsv($output, [
            '',
            __('TOTALES', 'wc-purchase-price'),
            '',
            '',
            '',
            '',
            number_format($inventory_data['total_investment'], 2, '.', ''),
            number_format($inventory_data['total_value'], 2, '.', ''),
            number_format($inventory_data['total_profit'], 2, '.', ''),
            number_format($inventory_data['average_margin'], 2, '.', '')
        ]);

        fclose($output);
        exit;
    }

    /**
     * Exportar el informe de ganancias a CSV
     */
    public function export_profit_report()
    {
        // Verificar nonce
        check_ajax_referer('export_profit_report_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes para realizar esta acción.', 'wc-purchase-price'));
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');

        // Conseguir todos los pedidos en el rango de fechas
        $args = [
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'date_created' => $start_date . '...' . $end_date,
            'type' => 'shop_order', // Especificar tipo para compatibilidad con HPOS
        ];

        $orders = wc_get_orders($args);

        // Procesar los datos para el informe
        $products_data = [];

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $actual_id = $variation_id ? $variation_id : $product_id;
                $product = $item->get_product();

                if (!$product) {
                    continue;
                }

                $quantity = $item->get_quantity();
                $total = $item->get_total();
                $purchase_price = get_post_meta($actual_id, '_purchase_price', true);

                if (!$purchase_price) {
                    $purchase_price = 0;
                }

                $purchase_cost = floatval($purchase_price) * $quantity;
                $profit = $total - $purchase_cost;

                // Combinar los datos por producto
                if (!isset($products_data[$actual_id])) {
                    $products_data[$actual_id] = [
                        'id' => $actual_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'quantity' => 0,
                        'revenue' => 0,
                        'cost' => 0,
                        'profit' => 0
                    ];
                }

                $products_data[$actual_id]['quantity'] += $quantity;
                $products_data[$actual_id]['revenue'] += $total;
                $products_data[$actual_id]['cost'] += $purchase_cost;
                $products_data[$actual_id]['profit'] += $profit;
            }
        }

        // Ordenar por ganancia (mayor a menor)
        usort($products_data, function ($a, $b) {
            return $b['profit'] <=> $a['profit'];
        });

        // Configurar cabeceras para descargar el CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=informe-ganancias-' . $start_date . '-a-' . $end_date . '.csv');

        // Abrir el output
        $output = fopen('php://output', 'w');

        // Añadir BOM UTF-8 para compatibilidad con Excel
        fputs($output, "\xEF\xBB\xBF");

        // Cabeceras CSV
        fputcsv($output, [
            __('ID', 'wc-purchase-price'),
            __('Producto', 'wc-purchase-price'),
            __('SKU', 'wc-purchase-price'),
            __('Cantidad', 'wc-purchase-price'),
            __('Ingresos', 'wc-purchase-price'),
            __('Costo', 'wc-purchase-price'),
            __('Ganancia', 'wc-purchase-price'),
            __('Margen %', 'wc-purchase-price')
        ]);

        // Datos CSV
        $total_quantity = 0;
        $total_revenue = 0;
        $total_cost = 0;
        $total_profit = 0;

        foreach ($products_data as $product_data) {
            $margin = ($product_data['revenue'] > 0) ? ($product_data['profit'] / $product_data['revenue'] * 100) : 0;

            fputcsv($output, [
                $product_data['id'],
                $product_data['name'],
                $product_data['sku'],
                $product_data['quantity'],
                number_format($product_data['revenue'], 2, '.', ''),
                number_format($product_data['cost'], 2, '.', ''),
                number_format($product_data['profit'], 2, '.', ''),
                number_format($margin, 2, '.', '')
            ]);

            $total_quantity += $product_data['quantity'];
            $total_revenue += $product_data['revenue'];
            $total_cost += $product_data['cost'];
            $total_profit += $product_data['profit'];
        }

        // Fila de totales
        $total_margin = ($total_revenue > 0) ? ($total_profit / $total_revenue * 100) : 0;

        fputcsv($output, [
            '',
            __('TOTALES', 'wc-purchase-price'),
            '',
            $total_quantity,
            number_format($total_revenue, 2, '.', ''),
            number_format($total_cost, 2, '.', ''),
            number_format($total_profit, 2, '.', ''),
            number_format($total_margin, 2, '.', '')
        ]);

        fclose($output);
        exit;
    }
}

// Directorio JS
if (!file_exists(WC_PURCHASE_PRICE_PLUGIN_DIR . 'js')) {
    mkdir(WC_PURCHASE_PRICE_PLUGIN_DIR . 'js', 0755);
}

// Crear archivo JS
$js_content = <<<EOT
jQuery(document).ready(function($) {
    // Exportar informe de ganancias
    $('#export-profit-report').on('click', function() {
        var data = {
            action: 'export_profit_report',
            nonce: wc_purchase_price.nonce,
            start_date: wc_purchase_price.start_date,
            end_date: wc_purchase_price.end_date
        };
        
        // Redirigir para la descarga
        var url = wc_purchase_price.ajax_url + '?' + $.param(data);
        window.location.href = url;
    });
    
    // Exportar informe de inventario
    $('#export-inventory-report').on('click', function() {
        var data = {
            action: 'export_inventory_report',
            nonce: wc_purchase_price.nonce
        };
        
        // Redirigir para la descarga
        var url = wc_purchase_price.ajax_url + '?' + $.param(data);
        window.location.href = url;
    });
});
EOT;

$js_file = WC_PURCHASE_PRICE_PLUGIN_DIR . 'js/admin.js';
if (!file_exists($js_file)) {
    file_put_contents($js_file, $js_content);
}

// Directorio CSS
if (!file_exists(WC_PURCHASE_PRICE_PLUGIN_DIR . 'css')) {
    mkdir(WC_PURCHASE_PRICE_PLUGIN_DIR . 'css', 0755);
}

// Crear archivo CSS
$css_content = <<<EOT
.profit-summary {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 15px;
    margin-bottom: 20px;
}

.summary-data {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    max-width: 800px;
}

.summary-item {
    flex: 0 0 22%;
    min-width: 180px;
    margin-right: 10px;
    margin-bottom: 10px;
}

.summary-item h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    font-weight: 600;
}

.summary-item .amount {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

/* Estilos responsivos */
@media screen and (max-width: 782px) {
    .summary-item {
        flex: 0 0 100%;
    }
}
EOT;

$css_file = WC_PURCHASE_PRICE_PLUGIN_DIR . 'css/admin.css';
if (!file_exists($css_file)) {
    file_put_contents($css_file, $css_content);
}

// Inicializar plugin
function wc_purchase_price_init()
{
    global $wc_purchase_price;
    $wc_purchase_price = new WC_Purchase_Price();
}
add_action('plugins_loaded', 'wc_purchase_price_init');
