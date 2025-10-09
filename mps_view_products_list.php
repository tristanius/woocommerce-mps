<?php
// Asegúrate de que WordPress esté cargado
if (! defined('ABSPATH')) {
    exit;
}

// Requerir el archivo de la lógica de precios si es necesario (asumiendo que está en mps_wc.php)
// Podría ser necesario incluir mps_wc.php aquí si las funciones de cálculo de precio no son globales.
// Para este ejemplo, asumiremos que las funciones clave están disponibles globalmente o se incluyen donde corresponde.

$products_data = get_products_data_for_list();

/**
 * Obtiene la información detallada de los productos para la tabla.
 * Es crucial que todas tus funciones de precio y UVT estén disponibles aquí (en mps_wc.php o similar).
 * Se asume que existe una función llamada 'calculate_final_price' y otra 'calculate_uvts'.
 * Tendrás que adaptar la llamada a las funciones de tu proveedor.
 */
function get_products_data_for_list() {
    
    // Obtenemos los productos de la página que tienen el prefijo MPS
    // La función get_all_products_in_page() ya está definida en MPS.php
    $page_products = get_all_products_in_page();
    
    $list_data = [];
    
    // Obtenemos el valor de la UVT y el IVA de las opciones guardadas
    $uvts_value = (float) get_option("uvts_value", 0);
    $iva_rate = (float) get_option("iva", 0);
    $uvt_exemption_limit = 50 * $uvts_value; // 50 UVT
    
    foreach ($page_products as $item) {
        $product_id = $item['id'];
        $product = wc_get_product($product_id);
        
        if (!$product) continue;
        
        // --- Obtención de categorías ---
        $categories = wc_get_product_terms($product_id, 'product_cat', array('fields' => 'names'));
        $category = !empty($categories) ? $categories[0] : 'N/A';
        $subcategory = count($categories) > 1 ? $categories[1] : 'N/A'; // Simplificación: toma la segunda como subcategoría
        $categories_json = json_encode($categories);
        
        // --- Obtención de Precios y UVT (DEBES ADAPTAR ESTAS LLAMADAS) ---
        // Aquí debes llamar a las funciones que ya tienes definidas en otros archivos de tu plugin
        
        // Ejemplo de cómo obtener los metadatos que guardas del WS de MPS
        $mps_base_price = (float) get_post_meta($product_id, '_price', true); // Precio del WS de MPS
        $kenner_percentage = (float) get_post_meta($product_id, '_kenner_percentage', true); // Tu margen
        
        // ** Simulación de Cálculos ** de precio.
        $price_with_margin = (float) get_post_meta($product_id, '_regular_price', true);
        
        /*sacamos información contenida del producto adicional 07/10/2055 agregado por YTORRADO no se da por útil
        $product_meta_info = get_post_meta($product_id);
         $_product_attributes ="";
        if(isset($product_meta_info["_product_attributes"])){
            $_product_attributes = json_encode( unserialize( $product_meta_info["_product_attributes"][0] ) );
        }*/
        
        $kenner_base_price = $price_with_margin;
        $final_price = $price_with_margin;
        
        //$price_is_tax_exempt = $price_with_margin <= $uvt_exemption_limit;
        $price_is_tax_exempt = $kenner_base_price <= $uvt_exemption_limit;
        
        $iva_amount = 0;
        
        // si el precio tiene IVA
        if (!$price_is_tax_exempt) {
            $iva_amount = $price_with_margin * ($iva_rate / 100);
            $kenner_base_price = $price_with_margin -($price_with_margin*(19/119)); //calculamos el precio sin IVA
            //$final_price += $iva_amount;
        }

        $list_data[] = [
            'id' => $product_id,
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'category' => $category,
            'subcategory' => $subcategory,
            'mps_price' => number_format($kenner_base_price, 0, ',', '.'),
            'final_price' => number_format($final_price, 0, ',', '.'),
            'uvts_status' => $price_is_tax_exempt ? 'Sí (Exento)' : 'No (Gravado)',
            'iva_status' => $price_is_tax_exempt ? '0%' : $iva_rate . '%',
            'uvt_value' => number_format($uvts_value, 0, ',', '.'),
            'meta_info' => $categories_json,
        ];
    }
    
    return $list_data;
}


?>

<div class="wrap">
    <h1 class="wp-heading-inline">Listado de Productos MPS</h1>
    <hr>
    <p>Visualización centralizada de productos sincronizados, con su información de precios y estado tributario (UVTs/IVA).</p>
    
    <?php if (empty($products_data)) : ?>
        <div class="notice notice-warning">
            <p>No se encontraron productos con el prefijo '<?php echo esc_html(MPS_PREFIX); ?>' en tu tienda.</p>
        </div>
    <?php else : ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" id="no" class="manage-column column-no" style="flex-grow: 1;">#</th>
                <th scope="col" id="sku" class="manage-column column-sku">SKU</th>
                <th scope="col" id="name" class="manage-column column-name">Nombre del Producto</th>
                <th scope="col" id="category" class="manage-column column-category">Categoría</th>
                <th scope="col" id="price" class="manage-column column-price">Precio sin IVA</th>
                <th scope="col" id="final_price" class="manage-column column-final_price">Precio final</th>
                <th scope="col" id="uvts" class="manage-column column-uvts">Aplica Exención UVT (50 UVT)</th>
                <th scope="col" id="iva" class="manage-column column-iva">IVA Aplicado</th>
                <th scope="col" id="meta_info" class="manage-column column-meta_info">meta info</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; ?>
            <?php foreach ($products_data as $product_info) : ?>
            <tr>
                <td class="no column-no" style="flex-grow: 1;"><?php 
                    echo $no;
                    $no++;
                    ?>
                </td>
                <td class="sku column-sku">
                    <strong><?php echo esc_html($product_info['sku']); ?></strong>
                </td>
                <td class="name column-name">
                    <a href="<?php echo esc_url(get_edit_post_link($product_info['id'])); ?>" target="_blank">
                        <?php echo esc_html($product_info['name']); ?>
                    </a>
                </td>
                <td class="category column-category">
                    <?php echo esc_html($product_info['category'] . ' / ' . $product_info['subcategory']); ?>
                </td>
                <td class="price column-price">
                    $<?php echo esc_html($product_info['mps_price']); ?>
                </td>
                <td class="final_price column-final_price">
                    <strong>$<?php echo esc_html($product_info['final_price']); ?></strong>
                </td>
                <td class="uvts column-uvts">
                    <?php 
                        if ($product_info['uvts_status'] === 'Sí (Exento)') {
                            echo '<span style="color: green; font-weight: bold;">' . esc_html($product_info['uvts_status']) . '</span>';
                        } else {
                            echo '<span style="color: orange; font-weight: bold;">' . esc_html($product_info['uvts_status']) . '</span>';
                        }
                    ?>
                </td>
                <td class="iva column-iva">
                    <?php echo esc_html($product_info['iva_status']); ?>
                </td>
                <td class="meta_info column-meta_info">
                    <?php
                    echo esc_html($product_info['meta_info']);
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col" style="flex-grow: 1;">No.</th>
                <th scope="col">SKU</th>
                <th scope="col">Nombre del Producto</th>
                <th scope="col">Categoría</th>
                <th scope="col">Precio sin IVA</th>
                <th scope="col">Precio final</th>
                <th scope="col">Aplica Exención UVT (50 UVT)</th>
                <th scope="col">IVA Aplicado</th>
                <th scope="col">Meta info</th>
            </tr>
        </tfoot>
    </table>

    <p style="margin-top: 20px; font-size: 0.9em;">* Valor de la UVT (Guardado en opciones): $<?php echo esc_html($product_info['uvt_value']); ?></p>

    <?php endif; ?>
</div>