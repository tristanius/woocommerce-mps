<?php

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WooCommerce')) {
    return;
}

/**
 * Consultar y actualizar productos de MPS.
 */
class Distributor_MPS
{
    public string $mps_prefix = MPS_PREFIX;

    private string $body_token;

    private string $token_link;

    private string $catalog_link;

    private string $create_order_link;

    private int $limit_price;

    private array $percentage_category;

    /**
     * 
     * @param string $body_token Información para obtener token.
     * 
     * @param string $token_link Endpoint para obtener el token.
     * 
     * @param string $catalog_link Endpoint para obtener los productos
     */
    public function __construct()
    {
        include "config.php";
        $this->body_token = $body_token;
        $this->token_link = $token_link;
        $this->catalog_link = $catalog_link;
        $this->create_order_link = $create_order_link;
        $this->limit_price = (int)get_option('mps_min_price');
        $this->percentage_category = get_option('mps_percentage_category');
    }

    /**
     * Get token
     * 
     * @return string Token api
     */
    public function get_token()
    {
        if ($this->get_local_token()) {
            return $this->get_local_token();
        }
        return $this->get_api_token();
    }

    /**
     * Get the token from the mps api and save it
     * 
     * @return string Token api
     */
    public function  get_api_token()
    {
        $arguments = [
            'headers'   => ['Content-Type' => 'text/plain'],
            'body'       => $this->body_token,
            'data_format' => 'body'
        ];

        $token = $this->request_api($this->token_link, $arguments, "token");

        return $token;
    }

    /**
     * Get the saved token
     * 
     * @return string Token api
     */
    public function get_local_token()
    {
        return get_option('mps_t')['token'];
    }

    /**
     * Get the MPS product by its SKU
     * 
     * @param $sku_product
     * 
     * @return array Product properties
     */
    public function get_product(string $sku_product)
    {
        $token = $this->get_token();
        $sku_product = substr($sku_product, strlen($this->mps_prefix));

        $arguments = [
            'headers' => [
                'Authorization' => "bearer " . $token,
                'content-type' => 'application/json',
                'X-DISPONIBILIDAD' => 1,
                'X-DESCUENTO' => 0,
                'X-PARTNUM' => $sku_product,
            ],
            'timeout' => 45,
        ];

        $product = $this->request_api($this->catalog_link, $arguments, "producto");

        return $product ? $product[0] : $product;
    }

    /**
     * Get all the MPS products
     * 
     * @return array All MPS products with their properties
     */
    public function get_all_products()
    {
        $token = $this->get_token();

        $arguments = [
            'headers' => [
                'Authorization' => "bearer $token",
                'content-type' => 'application/json',
                'X-DISPONIBILIDAD' => 1,
                'X-DESCUENTO' => 0,
            ],
            'timeout' => 90,
        ];

        $all_products = $this->request_api($this->catalog_link, $arguments, "productos");

        return $all_products;
    }

    /**
     * Create MPS product
     * 
     * @param $product Product with its properties
     */
    public function create_product(array $product)
    {
        $mps_prefix_sku = $this->mps_prefix . "" . $product["Sku"];

        if (wc_get_product_id_by_sku($mps_prefix_sku)) {
            mps_log('El producto ' . $mps_prefix_sku . ' esta duplicado.', 0);
            return;
        }

        if ($this->limit_price > (int)$product["precio"]) {
            mps_log('El producto ' . $mps_prefix_sku . ' no cumple con el precio minimo ya que su precio es ' . $product["precio"], 0);
            return;
        }

        $categories_to_public = $this->percentage_category;
        $new_product = new WC_Product_Simple();
        $new_product->set_name($product["Name"]);
        $new_product->set_description($product["Description"]);
        $new_product->set_sku($mps_prefix_sku);
        $new_product->set_manage_stock(true);
        $new_product->set_stock_quantity($product["Quantity"]);

        //set price
        $price = $this->set_price_kt($product["precio"], [$product["Categoria"], $product["Familia"], $product["Marks"]], $product["TributariClassification"]);
        $str_price = strval($price);
        $new_product->set_regular_price($str_price);
        
        // --- INICIO DE CÓDIGO AÑADIDO (CORREGIDO) ---
        // Establecer la clase de impuesto basado en la validación de IVA
        $iva_info = $this->calculate_iva_by_uvt($price, [$product["Categoria"], $product["Familia"], $product["Marks"]], $product["TributariClassification"]); // Agrego los parámetros para que sea explícito
        if (isset($iva_info['aplica_iva']) && $iva_info['aplica_iva'] === false) {
            $new_product->set_tax_class('Zero Rate'); // Asigna la clase de impuesto 'Tasa Cero'
        } else {
            $new_product->set_tax_class(''); // Asigna la clase de impuesto estándar
        }
        // --- FIN DE CÓDIGO AÑADIDO (CORREGIDO) ---
        // ...

        //set status
        if (array_key_exists($product["Categoria"], $categories_to_public)) {
            $new_product->set_status((int)$categories_to_public[$product["Categoria"]]['value'] !== 0 ? 'publish' : 'draft');
        } else {
            $new_product->set_status('draft');
        }

        //set sale price
        if ($product["Descuento"] != 0 or $product["Descuento"] != 0.0) {
            $sale_price = $price - $product["Descuento"];
            $str_sale_price = strval($sale_price);
            $new_product->set_sale_price($str_sale_price);
            unset($sale_price, $str_sale_price);
        }

        //set attributes
        if ($product["xmlAttributes"]) {
            $atributes = json_decode($product["xmlAttributes"], true);
            $atributes_product = $atributes["ListaAtributos"]["Atributos"]["attributecs"];
            $attributes = $this->create_attributes_to_product($atributes_product);
            $new_product->set_attributes($attributes);
            unset($atributes_product, $attributes);
        }

        //set categories
        $category_product = $this->get_or_create_product_category_id("Categorias", $product["Categoria"]);
        $family_product = $this->get_or_create_product_category_id("Familia", $product["Familia"]);
        $mark_product = $this->get_or_create_product_category_id("Marcas", $product["Marks"]);
        $new_product->set_category_ids([$category_product, $family_product, $mark_product]);
        $new_product->set_slug($product["slug"]);

        $product_id = $new_product->save();

        //set images links, store (custom fields)
        if ($product_id) {
            if (!empty($product["Imagenes"])) {
                $images = array_filter($product["Imagenes"]);
                $images = implode(",", $images);
                try {
                    update_post_meta($product_id, '_field_external_links', $images);
                } catch (Exception $e) {
                    mps_log("Error al agregar imagenes " . $e->getMessage(), 2);
                }
            }

            if (!empty($product["ListaProductosBodega"])) {
                $store = $product["ListaProductosBodega"][0]["Bodega"];
                try {
                    update_post_meta($product_id, '_store_mps', $store);
                } catch (Exception $e) {
                    mps_log("Error al agregar Almacen " . $e->getMessage(), 2);
                }
            }
            // Agregamos el precio base de mps (int), si es excento (boolean), precio base de kennertech
            if (!empty($product["precio"])) {
                $precio_mps = $product["precio"];
                try {
                    update_post_meta($product_id, '_precio_mps', $precio_mps); // aquí guardamos el precio base delproveedor
                    
                    $kenner_price = $this->calculate_kenner_price( $precio_mps, [$product["Categoria"], $product["Familia"]] ); //calculamos el precio  + el margen de ganancia de la empresa
                    $iva = $this->calculate_iva_by_uvt($kenner_price, [$product["Categoria"], $product["Familia"]] ); // calculamos el IVA y retorna un array

                    update_post_meta($product_id, '_precio_base', $kenner_price ); // llamamos una función de calculo de precio base + el margen de kenner para calcular. Se guarda como metadata
                    update_post_meta($product_id, '_aplica_iva', $iva["aplica_iva"] ); // llamamos una función de validación de IVA por UVTs true/false. Se guarda como metadata
                } catch (Exception $e) {
                    mps_log("Error al agregar IVA " . $e->getMessage(), 2);
                }
            }

        }

        unset($product, $new_product, $duplicado, $mps_prefix_sku, $price, $str_price, $category_product, $family_product, $mark_product, $product_id, $images, $store);
    }

    /**
     * Create all MPS products
     */
    public function create_all_products()
    {
        $products = $this->get_all_products();
        mps_log("Productos a crear " . count($products), 0);

        $batches = array_chunk($products, 50);

        $original_memory_limit = ini_get('memory_limit');

        if ($original_memory_limit != '4G') {

            ini_set('memory_limit', '4G');

            foreach ($batches as $batch_products) {

                foreach ($batch_products as $product) {

                    try {
                        $this->create_product($product);
                        wc_delete_product_transients();
                    } catch (Exception $e) {
                        mps_log("Error al crear producto: " . $e->getMessage(), 0);
                    }
                    unset($product);
                }

                unset($batch_products);
                wp_cache_flush();
                gc_collect_cycles();
                sleep(1);
            }

            unset($batches);

            ini_set('memory_limit', $original_memory_limit);
        }

        update_option("mps all products created", true);
        mps_log("Función create_all_products finalizada", 0);
    }

    /**
     * Update product MPS on page
     * 
     * @param $id_product Product ID on the page that want to update
     * 
     * @param $mps_product MPS product with its properties
     */
    public function update_product(int $id_product, array $mps_product)
    {
        $categories_to_public = $this->percentage_category;

        $product_in_page = wc_get_product($id_product);
        $product_in_page->set_stock_quantity($mps_product["Quantity"]);

        $price = $this->set_price_kt($mps_product["precio"], [$mps_product["Categoria"], $mps_product["Familia"], $mps_product["Marks"]], $mps_product["TributariClassification"]);
        $str_price = strval($price);
        $product_in_page->set_regular_price($str_price);
        $product_in_page->set_sale_price($str_price);

        // --- INICIO DE CÓDIGO AÑADIDO ---
        // Establecer la clase de impuesto basado en la validación de IVA
        $iva_info = $this->calculate_iva_by_uvt($price, [$mps_product["Categoria"], $mps_product["Familia"], $mps_product["Marks"]], $mps_product["TributariClassification"]);
        if (isset($iva_info['aplica_iva']) && $iva_info['aplica_iva'] === false) {
            $product_in_page->set_tax_class('Zero Rate'); // Asigna la clase de impuesto 'Tasa Cero'
        } else {
            $product_in_page->set_tax_class(''); // Asigna la clase de impuesto estándar si no está exento
        }
        // --- FIN DE CÓDIGO AÑADIDO ---
        // ...

        //set status
        if ($this->limit_price > $mps_product["precio"]) {
            $product_in_page->set_status('draft');
        } else {
            if (array_key_exists($mps_product["Categoria"], $categories_to_public)) {
                $product_in_page->set_status((int)$categories_to_public[$mps_product["Categoria"]]['value'] !== 0 ? 'publish' : 'draft');
            } else {
                $product_in_page->set_status('draft');
            }
        }

        if (!empty($mps_product["Imagenes"])) {
            $images = array_filter($mps_product["Imagenes"]);
            $images = implode(",", $images);
            try {
                update_post_meta($id_product, '_field_external_links', $images);
            } catch (Exception $e) {
                mps_log("Error al agregar imagenes " . $e->getMessage(), 2);
            }
        }

        // Agregamos el precio base de mps (int), si es excento (boolean), precio base de kennertech
        if (!empty($mps_product["precio"])) {
            $precio_mps = $mps_product["precio"];
            try {
                update_post_meta($id_product, '_precio_mps', $precio_mps); // aquí guardamos el precio base delproveedor
                
                $kenner_price = $this->calculate_kenner_price( $precio_mps, [$mps_product["Categoria"], $mps_product["Familia"]] ); //calculamos el precio  + el margen de ganancia de la empresa
                $iva = $this->calculate_iva_by_uvt($kenner_price, [$mps_product["Categoria"], $mps_product["Familia"]] );  // calculamos el IVA y retorna un array

                update_post_meta($id_product, '_precio_base', $kenner_price ); // llamamos una función de calculo de precio base + el margen de kenner para calcular. Se guarda como metadata
                update_post_meta($id_product, '_aplica_iva', $iva["aplica_iva"] ); // llamamos una función de validación de IVA por UVTs true/false. Se guarda como metadata
            } catch (Exception $e) {
                mps_log("Error al agregar IVA " . $e->getMessage(), 2);
            }
        }

        $product_in_page->save();
        unset($product_in_page, $price, $str_price, $categories_to_public);
    }

    /**
     * Update all products on page
     */
    public function update_all_products()
    {
        $all_products_mps = $this->get_all_products();

        foreach ($all_products_mps as $mps_product) {
            $product_id_in_page = wc_get_product_id_by_sku($this->mps_prefix . $mps_product["Sku"]);
            if ($product_id_in_page) {
                $this->update_product($product_id_in_page, $mps_product);
            } else {
                $this->create_product($mps_product);
                mps_log('Nuevo producto creado: ' . $mps_product['Sku'] . ' Categoria: ' . $mps_product['Categoria'], 0);
            }

            unset($mps_product, $product_id_in_page);
        }

        delete_products($all_products_mps);

        wc_delete_product_transients();
        unset($all_products_mps, $product_id_in_page);
    }

    /**
     * Send a request to the MPS API to create an MPS order
     * 
     * @param $order_id Order id on page
     * 
     * @param $order_list List of products to order
     * 
     * @return string|false Id of the order created in mps
     */
    public function create_order(int $order_id, array $order_list)
    {

        $order = [
            "listaPedido" => [
                [
                    "AccountNum"           => "900732983",
                    "NombreClienteEntrega" => "Kennertech S.A.S",
                    "ClienteEntrega"       => "900732983",
                    "TelefonoEntrega"      => "9999999",
                    "DireccionEntrega"     => "Calle 1",
                    "StateId"              => "11",
                    "CountyId"             => "001",
                    "RecogerEnSitio"       => 0,
                    "EntregaUsuarioFinal"  => 1,
                    "dlvTerm"              => "",
                    "dlvmode"              => "",
                    "Observaciones"        => "Tienda Kennertech pedido #$order_id",
                    "listaPedidoDetalle"   => $order_list,
                ]
            ]
        ];

        $arguments = [
            'headers'   => [
                'Authorization' => 'Bearer ' . $this->get_token(),
                'Content-Type'  => 'application/json'
            ],
            'body'      => json_encode($order),
            'timeout'   => 60,
        ];

        $mps_order = $this->request_api($this->create_order_link, $arguments, "pedido");



        if ($mps_order) {
            if ($mps_order["valor"] == "1") {
                $mps_data_order["mps_order"] = $order_list;
                $mps_data_order["wc_order"] = $order_id;
                $mps_data_order["mps_order_number"] = $mps_order["pedido"];
                $message = "Pedido tienda Kennertech " . $order_id . ". Pedido distribuidor MPS: " . $mps_order["pedido"] . "\n\nDetalle del pedido: \n";

                foreach ($order_list as $product) {
                    $message .= "Cantidad: " . $product["Cantidad"] . "\nSKU: " . $product["PartNum"] . "\nBodega: " . $product["Bodega"] . "\nMarca: " . $product["Marks"] . "\n\n";
                }

                mps_log($message, 0);
                $message = str_replace("\n", "<br>", $message);
                mps_email_notification($message, 0);
                update_post_meta($order_id, "mps_order", $mps_data_order);

                return $mps_order["pedido"];
            } else {
                $mps_data_order["mps_order"] = $order_list;
                $mps_data_order["wc_order"] = $order_id;
                $mps_data_order["mps_order_number"] = "FAIL";
                $message = "Pedido tienda Kennertech " . $order_id . ". Pedido distribuidor MPS: NO SE PUDO CREAR. Error en los datos enviados a la api de MPS. Información enviada: " . json_encode($order);
                update_post_meta($order_id, "mps_order", $mps_data_order);
                mps_log($message, 2);
                mps_email_notification($message, 2);
                mps_log(json_encode($mps_data_order), 1);
                return false;
            }
        } else {
            $mps_data_order["mps_order"] = $order_list;
            $mps_data_order["wc_order"] = $order_id;
            $mps_data_order["mps_order_number"] = "ERROR";
            $message = "Pedido tienda Kennertech " . $order_id . ".No hay respuesta de la api de MPS no se ha podido crear el pedido en MPS";
            update_post_meta($order_id, "mps_order", $mps_data_order);
            mps_log($message, 2);
            mps_email_notification($message, 2);
            mps_log(json_encode($mps_data_order), 1);
            return false;
        }
    }

    /**
     * Get the product category ID or create it if it doesn't exist and return its ID and add it as a child of the parent category passed to it
     * 
     * @param $parent_cat_name Parent category name
     * 
     * @param $sub_cat_name Category name
     * 
     * @return int|WP_Error
     */
    public function get_or_create_product_category_id(string $parent_cat_name, string $sub_cat_name)
    {
        $parent_term = get_term_by('name', $parent_cat_name, 'product_cat');

        if (!$parent_term) {
            $parent_term = wp_insert_term($parent_cat_name, 'product_cat');

            if (is_wp_error($parent_term)) {
                return new WP_Error('category_creation_failed', 'No se pudo crear la categoría principal');
            }

            $parent_cat_id = $parent_term['term_id'];
        } else {
            $parent_cat_id = $parent_term->term_id;
        }

        $sub_term = get_term_by('name', $sub_cat_name, 'product_cat');

        if (!$sub_term) {
            $sub_term = wp_insert_term($sub_cat_name, 'product_cat', array(
                'parent' => $parent_cat_id
            ));

            if (is_wp_error($sub_term)) {
                return new WP_Error('subcategory_creation_failed', 'No se pudo crear la subcategoría');
            }

            $sub_cat_id = $sub_term['term_id'];
        } else {
            if ($sub_term->parent != $parent_cat_id) {
                return new WP_Error('subcategory_wrong_parent', 'La subcategoría no está asociada a la categoría principal proporcionada');
            }

            $sub_cat_id = $sub_term->term_id;
        }

        return $sub_cat_id;
    }

    /**
     * Create an objet WC_Product_Attribute for every attribute
     * 
     * @param $attributes  
     * 
     * @return array
     */
    public function create_attributes_to_product(array $attributes)
    {
        $productAttributes = array();

        if (isset($attributes["AttributeName"])) {
            $new_attribute = new WC_Product_Attribute();
            $new_attribute->set_name($attributes["AttributeName"]);
            $new_attribute->set_options(array($attributes["AttributeValue"]));
            $new_attribute->set_position(0);
            $new_attribute->set_visible(true);
            $new_attribute->set_variation(false);
            $new_attribute->set_id(0);

            $productAttributes[$attributes["AttributeName"]] = $new_attribute;
        } else {
            foreach ($attributes as $key => $attribute) {
                $new_attribute = new WC_Product_Attribute();
                $new_attribute->set_name($attribute["AttributeName"]);
                $new_attribute->set_options(array($attribute["AttributeValue"]));
                $new_attribute->set_position($key);
                $new_attribute->set_visible(true);
                $new_attribute->set_variation(false);
                $new_attribute->set_id(0);

                $productAttributes[$attribute["AttributeName"]] = $new_attribute;
            }
        }


        return $productAttributes;
    }

    /**
     * Get a product for your sku according to the list of products that are passed
     * 
     * @return array|null
     */
    public function find_product_by_sku(array $list_products, string $sku_product)
    {
        $index = array_search($sku_product, array_column($list_products, 'Sku'));
        return $index !== false ? $list_products[$index] : null;
    }

    /**
     * Controller for requests to the MPS API
     * 
     * @param string $request_type Request type (token | pedido | producto | productos)
     * 
     * @param int $attempts Request attempts
     * 
     * @return false|mixed
     */
    private function request_api(string $endpoint, array $args, string $request_type, int $attempts = 1)
    {
        $start_time = microtime(true);
        $response = wp_remote_post($endpoint, $args);
        $end_time = microtime(true);
        $response_time = $end_time - $start_time;
        mps_log("tiempo de respuesta de solicitud a MPS api: " . $response_time . "s", 0);

        if (is_array($response) && !is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code == 200 && !empty($response_body)) {
                $mps_data = json_decode($response_body, true);

                $message = "Se ha obtenidos lo datos correctamente."
                    . PHP_EOL .
                    "Tipo de solicitud: "
                    . $request_type
                    . PHP_EOL .
                    "Intento: "
                    . $attempts;

                mps_log($message, 0);

                if ($request_type == "token") {

                    $token = [
                        'token' => $mps_data["access_token"],
                        'expiration' => $mps_data[".expires"],
                    ];

                    update_option('mps_t', $token);
                    return $mps_data["access_token"];
                }

                if ($request_type == "pedido") {
                    return $mps_data[0];
                }

                return $mps_data["listaproductos"];
            } else {
                $message = "Error en la respuesta de la api. Datos no encontrados."
                    . PHP_EOL
                    . $response_code
                    . PHP_EOL
                    . $response_body
                    . PHP_EOL
                    . "Tipo de solicitud: "
                    . $request_type
                    . PHP_EOL
                    . "Intento: "
                    . $attempts
                    . PHP_EOL
                    . "Tiempo de respuesta: "
                    . $response_time;

                mps_log($message, 2);
                if ($attempts < 2) {
                    $this->request_api($endpoint, $args, $request_type, $attempts + 1);
                } else {
                    mps_email_notification($message, 2);
                    mps_log("Correo enviado: Error en la respuesta de la api. Datos no encontrados.", 2);
                    return false;
                }
            }
        } else {
            $message = "Error al hacer la solicitud a la api."
                . PHP_EOL
                . "Respuesta de la api: "
                . $response->get_error_message()
                . PHP_EOL
                . "Tipo de solicitud: "
                . $request_type
                . PHP_EOL
                . "Intento: "
                . $attempts
                . PHP_EOL
                . "Tiempo de respuesta: "
                . $response_time;

            mps_log($message, 2);
            if ($attempts < 2) {
                $this->request_api($endpoint, $args, $request_type, $attempts + 1);
            } else {
                mps_email_notification($message, 2);
                mps_log("Correo enviado: Error al hacer la solicitud a la api.", 2);
                return false;
            }
        }
    }

    /**
     * Calculate percentage
     * 
     * @param float|int $value Value from which the percentage must be obtained
     * 
     * @return float|int
     */
    private function calculate_percentage(float|int $value, int $percentage)
    {
        return ($value * $percentage) / 100;;
    }

    /**
     * Calculate iva
     * 
     * @return float|int
     */
    private function calculate_iva(float|int $product_price)
    {
        return $this->calculate_percentage($product_price, get_option("iva"));
    }

    /**
     * Validate if the product qualifies for UVTS based on its categories.
     * If applicable, validate if it exceeds the limits.
     * If the limits are exceeded, VAT will be applied. Otherwise, the product will be exempt from VAT.
     * 
     * @return bool[] An array with two boolean elements:
     *                - applied uvts.
     *                - exceeds uvts.
     */
    private function check_uvts(float|int $product_price, array $product_categories)
    {
        $uvts = [
            "applied_uvts" => false,
            "exceeds_uvts" => false,
        ];

        $uvts_info = get_option('uvts');
        $firts_limit = $uvts_info['firts_limit'] * $uvts_info['value'];
        $second_limit = $uvts_info['second_limit'] * $uvts_info['value'];

        //Esto debe ser gestionado en una BD
        $categories_limits = [
            'PCs' => $firts_limit,
            'PORTATILES' => $firts_limit,
            'COMPUTADORES' => $firts_limit,
            "APPLE IMAC" => $firts_limit,
            "APPLE MAC" => $firts_limit,
            "LENOVOPCSCORPORATIVOS" => $firts_limit,
            'CELULARES' => $second_limit,
            'TABLETS' => $second_limit,
            "CELULARES Y TABLETS" => $second_limit,
            "APPLE IPHONE" => $second_limit,
            "LENOVOTABLETS" => $second_limit,
        ];

        foreach ($product_categories as $category) {
            if (array_key_exists($category, $categories_limits)) {
                $uvts["applied_uvts"] = true;
                $uvts_limit = $uvts_info['value'] * $categories_limits[$category];
                if ($product_price > $uvts_limit) {
                    $uvts["exceeds_uvts"] = true;
                }
            }
        }

        return $uvts;
    }

    /**
     * Adjust price.
     * 
     * @param string[] $categories
     * 
     * @return int|array
     */
    private function set_price_kt(float|int $mps_price, array $categories, string $tri_clas, bool $report = false)
    {
        $categories_to_public = $this->percentage_category;

        $percentage_kt = (int)$categories_to_public[$categories[0]]['value'] ?? 0;
        $percentage_kt_value = $percentage_kt > 0 ? $this->calculate_percentage($mps_price, $percentage_kt) : 0;
        $kenner_price = $mps_price + $percentage_kt_value;

        if ($tri_clas != "GRAVADO") {
            $uvts = $this->check_uvts($mps_price + $percentage_kt_value, $categories);
            if ($uvts["applied_uvts"]) {
                $iva = $uvts["exceeds_uvts"] ? $this->calculate_iva($kenner_price) : 0;
                $uvts_exempt = $iva != 0 ? "aplica iva" : "excento de iva";
            } else {
                $iva = $this->calculate_iva($kenner_price);
                $uvts_exempt = "no aplica";
            }
            $iva = 0;
        } else {
            $iva = $this->calculate_iva($kenner_price);
        }


        $kenner_price += $iva;

        $product_price = intval($kenner_price);

        if ($report) {
            return [
                "Precio MPS" => $mps_price,
                "Porcentaje kt" => $percentage_kt,
                "Valor porcentaje" => $percentage_kt_value,
                "Precio + porcentaje" => $mps_price + $percentage_kt_value,
                "Aplica uvts" => $uvts["applied_uvts"] ? "aplica uvts" : "no aplica uvts",
                "Exento de iva" => $uvts_exempt,
                "Clasificación tributaaria" => $tri_clas,
                "iva" => $iva,
                "Precio final" => $product_price,
            ];
        } else {
            return $product_price;
        }
    }

    private function calculate_kenner_price( float|int $mps_price, array $categories ){
        $kenner_price = 0;
        $categories_to_public = $this->percentage_category;

        $percentage_kt = (int)$categories_to_public[$categories[0]]['value'] ?? 0;

        $percentage_kt_value = $percentage_kt > 0 ? $this->calculate_percentage($mps_price, $percentage_kt) : 0;
        $kenner_price = $mps_price + $percentage_kt_value;

        return $kenner_price;
    }

    private function calculate_iva_by_uvt( float|int $kenner_price, array $categories ){
        $iva = [
            "aplica_iva"=>true,
            "valor_iva"=>0,
        ];
        $uvts = [
            "applied_uvts" => false,
            "exceeds_uvts" => false,
        ];

        //validamos la categoria y almacenamos la validación boolean en $uvts
        $uvts = $this->check_uvts($kenner_price, $categories);

        // si categoria de product y las excentas hacen match "applied_uvts" será true pero "exceeds_uvts" indica que no es un valor > montos limites
        if ($uvts["applied_uvts"] && !$uvts["exceeds_uvts"]) { 
            $iva["aplica_iva"] = false; // marcar que la propiedad aplica_iva es falso                      
        }else{
            $iva["valor_iva"] = $this->calculate_iva($kenner_price); // En caso que no haga match con las categorías excentas calcular IVA igualmente
        }

        return $iva;
    }


    public function create_report_prices()
    {

        $mps_products = $this->get_all_products();
        $all_report = [];

        foreach ($mps_products as $product) {
            $prices_info = $this->set_price_kt(
                $product["precio"],
                [$product["Categoria"], $product["Familia"], $product["Marks"]],
                $product["TributariClassification"],
                true
            );

            $all_report[] = [
                "sku" => $product["Sku"],
                "Nombre" => $product["Name"],
                "Categoria" => $product["Categoria"]
            ] + $prices_info;
        }

        mps_report_prices($all_report);
    }
}
