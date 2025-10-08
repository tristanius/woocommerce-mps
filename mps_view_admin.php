<?php
/* Template Name: MPS menu */
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$uvts = get_option('uvts');
?>

<style type="text/css">
    .tg {
        border-collapse: collapse;
        border-spacing: 0;
    }

    .tg td {
        border-color: black;
        border-style: solid;
        border-width: 1px;
        font-family: Arial, sans-serif;
        font-size: 14px;
        overflow: hidden;
        padding: 10px 5px;
        word-break: normal;
    }

    .tg th {
        border-color: black;
        border-style: solid;
        border-width: 1px;
        font-family: Arial, sans-serif;
        font-size: 14px;
        font-weight: normal;
        overflow: hidden;
        padding: 10px 5px;
        word-break: normal;
    }

    .tg .tg-0pky {
        border-color: inherit;
        text-align: left;
        vertical-align: top
    }

    .tg .tg-0lax {
        text-align: left;
        vertical-align: top
    }

    .pre__kt {
        background-color: #f4f4f4;
        border: 1px solid #ddd;
        border-left: 3px solid #33f34d;
        color: #666;
        page-break-inside: avoid;
        font-family: monospace;
        font-size: 15px;
        line-height: 1.6;
        max-width: 100%;
        overflow: auto;
        padding: 1em 1.5em;
        margin: 0;
        display: block;
        word-wrap: break-word;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .mps__log {
        border: solid 2px #666;
        margin-right: 15px;
    }

    .mps__log_title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-right: 20px;
        border-bottom: solid 2px #666;
    }

    .mps__log_container {
        display: none;
    }

    #mps__show_hide_log {
        cursor: pointer;
        background-color: #666;
        color: #fff;
        border: none;
        text-align: center;
    }

    /*tabla de porcentajes */
    td {
        border-width: 2px;
        border-style: solid;
        border-color: #000;
    }
</style>
<div style="background-color: #fed700; padding: 20px 0; margin-bottom: 20px;">
    <h1 style="margin-left: 20px;font-weight: 900;">MPS</h1>
</div>
<div style="display: flex; gap: 20px;">
    <!-- left -->
    <div style="width: 25%;">
        <!-- UVTS -->
        <div style="margin-bottom: 20px">
            <table style="width: -webkit-fill-available;">
                <thead>
                    <tr>
                        <th colspan="2">UVTS</th>
                    </tr>
                </thead>
                <tbody id="uvts_data">
                    <tr>
                        <td>
                            Valor actual
                        </td>
                        <td>
                            <input type="text" value="<?php echo $uvts['value'] ?? 0; ?>" name="value" min="0">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            PCs y laptops
                        </td>
                    </tr>
                    <tr>
                        <td>
                            Primer limite
                        </td>
                        <td>
                            <input type="number" value="<?php echo $uvts['firts_limit'] ?? 0; ?>" name="firts_limit" min="0">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            Tablets y celulares
                        </td>
                    </tr>
                    <tr>
                        <td>
                            Segundo limite
                        </td>
                        <td>
                            <input type="number" value="<?php echo $uvts['second_limit'] ?? 0; ?>" name="second_limit" min="0">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button id="mps_update_uvts">Actualizar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- IVA -->
        <div style="margin-bottom: 20px">
            <table style="width: -webkit-fill-available;">
                <thead>
                    <tr>
                        <th colspan="2">Porcentaje IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input type="text" value="<?php echo get_option("iva"); ?>" name="iva" id="iva" min="0">
                        </td>
                        <td>
                            <button id="mps_update_iva">Actualizar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- Limit price -->
        <div style="margin-bottom: 20px">
            <table style="width: -webkit-fill-available;">
                <thead>
                    <tr>
                        <th colspan="2">Precio minimo de los productos a publicar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input type="text" value="<?php echo get_option('mps_min_price'); ?>" name="min_price" id="min_price" min="0">
                        </td>
                        <td>
                            <button id="mps_update_min_price">Actualizar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- porcentages -->
        <div>
            <table style="border: solid 2px #000">
                <caption>Porcentajes por categorias</caption>
                <thead>
                    <tr>
                        <th>Categorias principales MPS</th>
                        <th>Porcentajes</th>
                    </tr>
                </thead>
                <tbody id="percentage_category">
                    <?php
                    // parent category 
                    $parent_category = get_term_by('name', 'Categorias', 'product_cat');
                    $percentage_category = get_option('mps_percentage_category');

                    if ($parent_category) {
                        // get childs categories
                        $args = array(
                            'taxonomy'   => 'product_cat',
                            'child_of'   => $parent_category->term_id,
                            'hide_empty' => false,
                        );

                        $child_categories = get_terms($args);

                        // get childs categories
                        foreach ($child_categories as $key => $child) { ?>
                            <tr style="background: <?php echo ($key % 2 == 0) ? 'none' : 'gray'; ?>;">
                                <td>
                                    <b><?php echo $child->name; ?></b>
                                </td>
                                <td>
                                    <input
                                        value="<?php echo $percentage_category[$child->name]['value'] ?? 0; ?>"
                                        type="number"
                                        name="<?php echo $child->name; ?>"
                                        min="0"
                                        data="<?php echo $child->term_id; ?>">
                                </td>
                            </tr>
                    <?php }
                    } else {
                        echo 'La categoría padre no existe.';
                    }
                    ?>
                </tbody>
            </table>
            <button id="mps_update_categories_percentages" style="width: 100%; padding: 10px;">Actualizar porcentajes</button>
            <p style="color: red; font-size: 20px;">
                Despues de actualizar los porcentajes debe oprimir el boton de <b>sincronizar productos</b> para que se actualicen los precios de todos los productos.
            </p>
        </div>
    </div>
    <!-- right -->
    <div style="width: -webkit-fill-available;">
        <!-- buttons -->
        <div style="display: flex; margin: 10px 0; gap: 10px;">
            <button id="mps_sync_products">Sincronizar productos</button>
            <button id="mps_get_token">Obtener token</button>
            <button id="mps_start_create_report_prices">Crear reporte de precios</button>
            <?php if (current_user_can('administrator')): ?>
                <button id="mps_create_products">Crear productos</button>
                <button id="mps_test_create_order">Crear pedido</button>
                <button id="mps_test_create_products">Crear productos de prueba</button>
            <?php endif ?>
        </div>
        <!-- token -->
        <div>
            <h2>Token actual</h2>
            <p style="word-break: break-all;
            background-color: #bfbfbf;
            margin-right: 15px;
            padding: 10px;">
                <?php
                if (get_option('mps_t')) {
                    // echo get_distributor_local_token("MPS");
                    echo get_option('mps_t')['token'];
                } else {
                    echo "no hay token";
                }
                ?>
            </p>
        </div>
        <!-- log -->
        <div class="mps__log">
            <div class="mps__log_title">
                <h2>MPS Log</h2>
                <button id="mps__show_hide_log">X</button>
            </div>
            <div class="mps__log_container" style="
    max-height: 500px;
    width: 100%;
    overflow-y: scroll;
    ">
                <pre class="pre__kt">
        <?php mps_get_content_log(); ?>
            </pre>
            </div>
        </div>
    </div>
</div>
<?php
$test = get_option('mps_min_price');
?>
<script>
    jQuery(document).ready(() => {
        jQuery(document).on('click', '#mps__show_hide_log', () => {
            jQuery('.mps__log_container').toggle("slow")
        })
    })
</script>
<!-- //TODO: create a log of the process creating the products then show a tables with important info -->