<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**************************************************************Validate product availability***********************************************************************************/
add_action('woocommerce_before_checkout_form', 'validate_product_availability');

function validate_product_availability()
{
    $carrito = WC()->cart->get_cart();
    $mps = new Distributor_MPS();
    $cart_updated = false;

    foreach ($carrito as $item) {

        $product = wc_get_product($item['product_id']);
        $desired_quantity = $item['quantity'];
        $distributor_product = null;
        $available_quantity = null;

        if (str_contains($product->get_sku(), $mps->mps_prefix)) {

            $distributor_product = $mps->get_product($product->get_sku());

            if (!$distributor_product) {
                $message = "Fallo en la petición de la api MPS para verificar la disponibilidad de los productos en el carrito";
                mps_log($message, 1);
                mps_email_notification($message, 1);
                break;
            }

            $available_quantity = $distributor_product["Quantity"];

            if ($available_quantity === false) {
                wc_add_notice(__('Error al verificar stock. Inténtalo de nuevo.', 'woocommerce'), 'error');
                return;
            }

            if ($available_quantity < $desired_quantity && $available_quantity > 0) {
                mps_log("ajustando cantidad", 0);
                $mps->update_product($item['product_id'], $distributor_product);
                WC()->cart->set_quantity($item['key'], $available_quantity);
                mps_log("ajustado", 0);
                $cart_updated = true;
            }

            if ($available_quantity <= 0) {
                mps_log("eliminar producto", 0);
                $mps->update_product($item['product_id'], $distributor_product);
                WC()->cart->remove_cart_item($item['key'], $available_quantity);
                mps_log("producto eliminado del carro", 0);
                $cart_updated = true;
            }
        }
    }

    if ($cart_updated) {
        wc_add_notice('Algunos productos se han eliminado o actualizado, el stock ha cambiado.', 'notice');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }
}


/**************************************************************custom wc field to save profuct images links********************************************************************/
add_action('woocommerce_product_options_general_product_data', 'field_external_links');

function field_external_links()
{
    global $woocommerce, $post;
    echo '<div class="options_group">';
    woocommerce_wp_textarea_input(
        array(
            'id' => '_field_external_links',
            'label' => __('Imagenes externas del producto', 'woocommerce'),
            'placeholder' => __('Ingresa los links separados por comas', 'woocommerce'),
            'desc_tip' => 'true',
            'description' => __('Añade varios links separados por comas.', 'woocommerce')
        )
    );
    echo '</div>';
}

// Guardar el campo personalizado
add_action('woocommerce_process_product_meta', 'save_field_external_links');

function save_field_external_links($post_id)
{
    $custom_field = isset($_POST['_field_external_links']) ? sanitize_text_field($_POST['_field_external_links']) : '';
    update_post_meta($post_id, '_field_external_links', $custom_field);
}


/**************************************************************custom wc field to save warehouse of product********************************************************************/
add_action('woocommerce_product_options_general_product_data', 'field_store_mps');

function field_store_mps()
{
    global $woocommerce, $post;
    echo '<div class="options_group">';
    woocommerce_wp_text_input(
        array(
            'id' => '_store_mps',
            'label' => __('Almacen.', 'woocommerce'),
            'placeholder' => __('Almacen.', 'woocommerce'),
            'desc_tip' => 'true',
            'description' => __('Almacen.', 'woocommerce')
        )
    );
    echo '</div>';
}

add_action('woocommerce_process_product_meta', 'save_field_store_mps');

function save_field_store_mps($post_id)
{
    $custom_field = isset($_POST['_store_mps']) ? sanitize_text_field($_POST['_store_mps']) : '';
    update_post_meta($post_id, '_store_mps', $custom_field);
}


/**************************************************************create MPS order when the wc order change to processing*********************************************************/
add_action('woocommerce_order_status_processing', 'custom_order_payment_complete');

function custom_order_payment_complete($order_id)
{
    // Get order ID
    $order = wc_get_order($order_id);

    $list_order = [];

    $items = $order->get_items();
    foreach ($items as $item) {
        $order_mps = [];
        $order_mps["Cantidad"] = $item->get_quantity();

        if ($item instanceof WC_Order_Item_Product) {
            $product = $item->get_product();
            $order_mps["PartNum"] = substr($product->get_sku(), strlen(MPS_PREFIX));
            $order_mps["Bodega"] = get_post_meta($item->get_product_id(), '_store_mps', true);
            $order_mps["Marks"] = get_mark($product->get_id());
        }

        $list_order[] = $order_mps;
    }

    $mps = new Distributor_MPS();
    $mps_order = $mps->create_order($order_id, $list_order);
    if ($mps_order) {
        $order->add_order_note("Se ha creado el pedido MPS: " . $mps_order);
    } else {
        $message = "Error al crear el pedido MPS para el pedido #" . $order_id . ". Por favor crear pedido de manera manual.";
        $order->add_order_note($message);
        mps_log($message, 1);
        mps_email_notification($message, 2);
    }
}


/**************************************************************get product's mark by his subactegory marks********************************************************************/
function get_mark($product_id)
{
    // Obtener las categorías del producto
    $product_categories = wp_get_post_terms($product_id, 'product_cat');

    // Obtener la categoría padre
    $parent_category = get_term_by('slug', 'marcas', 'product_cat');

    if ($parent_category) {
        foreach ($product_categories as $category) {
            if ($category->parent == $parent_category->term_id) {
                return $category->name;
                break;
            }
        }
    }
}


/**************************************************************Metabox to show info and state of MPS order in wc order********************************************************/
add_action('add_meta_boxes', 'mi_meta_box_personalizada_pedido');

function mi_meta_box_personalizada_pedido()
{
    add_meta_box(
        'pedidos_mps',
        'Pedido MPS',
        'mps_orders_meta_box',
        'shop_order',
        'normal',
        'default'
    );
}

// Función de callback para renderizar el contenido
function mps_orders_meta_box($post)
{
?>
    <table style="width: 100%; border-collapse: collapse; background-color: #42f54826;">
        <thead>
            <tr>
                <th style="border: 1px solid #ccc; padding: 5px;">
                    Productos solicitados
                </th>
                <th style="border: 1px solid #ccc; padding: 5px;">
                    Numero del pedido
                </th>
                <th style="border: 1px solid #ccc; padding: 5px;">
                    Estado del pedido
                </th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="border: 1px solid #ccc; padding: 5px;">
                    <?php
                    $data = get_post_meta($post->ID, "mps_order", true) ? get_post_meta($post->ID, "mps_order", true) : "pedido aun no procesado";
                    $order = wc_get_order($post->ID);
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product() ? $item->get_product() : "no puede obtener el producto";
                        $sku = $product ? substr($product->get_sku(), strlen(MPS_PREFIX)) : 'Sin SKU';
                        echo "SKU: " . $sku . "<br>";
                        echo "Cantidad: " . $item->get_quantity() . "<br>";
                        echo "Marca: " . get_mark($product->get_id()) . "<br>";
                        echo "Almacen: " . get_post_meta($item->get_product_id(), '_store_mps', true) . "<br>";
                        echo "<hr>";
                    }
                    ?>

                </td>
                <td style="border: 1px solid #ccc; padding: 5px;">
                    <?php if ($data == "pedido aun no procesado") {
                        echo $data;
                    } else {
                        echo $data["mps_order_number"];
                    } ?>
                </td>
                <td style="border: 1px solid #ccc; padding: 5px;">
                    <?php
                    if ($data == "pedido aun no procesado") {
                        echo $data;
                    } else {
                        if ($data["mps_order_number"] == "FAIL" or $data["mps_order_number"] == "ERROR"):
                    ?>
                            <button id="mps_create_order" class="button" style="width: 100%;" data-post-id="<?php echo get_the_ID(); ?>">Crear</button>
                        <?php else: ?>
                            <p>Pedido MPS creado</p>
                    <?php endif;
                    }
                    ?>

                </td>
            </tr>
        </tbody>
    </table>
<?php
}
