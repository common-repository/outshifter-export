<?php

/** @noinspection ALL */
/**
 * Plugin Name: Reachu Export
 * Plugin URI: https://dashboard.reachu.io/
 * Description: Export products easily from your wocoomerce to Reachu platform.
 * Author: Reachu
 * Author URI: https://reachu.io/
 * Version: 3.8
 */


define('OSEWCPHJC_PLUGIN_PATH', __DIR__);
define('OSEWCPHJC_PLUGIN_FOLDER', plugin_dir_url(dirname(__FILE__)));
define('OSEWCPHJC_NAME_FOLDER', 'outshifter-export');
define('OSEWCPHJC_reachu_ORIGIN', 'reachu-origin');

//PROD
define('OSEWCPHJC_API_URL', 'https://api.reachu.io');

//QA
//define('OSEWCPHJC_API_URL', 'https://api-qa.reachu.io');

//LOCAL
// define('OSEWCPHJC_API_URL', 'https://a6134deb7b93.ngrok.io');

define('OSEWCPHJC_FIELD_UID', 'reachu-uid');
define('OSEWCPHJC_FIELD_APIKEY', 'reachu-apikey');
define('OSEWCPHJC_FIELD_PRODUCT_ID', 'reachu-product-id');
define('OSEWCPHJC_FIELD_SQS_ID', 'reachu-sqs-id');
define('OSEWCPHJC_FIELD_SYNC_ENABLED', 'reachu-sync-enabled');

require_once(OSEWCPHJC_PLUGIN_PATH . '/vendor/autoload.php');

add_action('plugins_loaded', 'woocommerce_reachu_init', 0);

function woocommerce_reachu_init()
{

  class OSEWCPHJC_reachuSync
  {
    private static $log;

    public static function init()
    {
      add_filter('woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50);
      add_action('woocommerce_settings_tabs_settings_reachu', __CLASS__ . '::settings_tab');
      add_action('woocommerce_update_options_settings_reachu', __CLASS__ . '::update_settings');
      add_filter('bulk_actions-edit-product', __CLASS__ . '::enviar_productos');
      add_filter('manage_edit-product_columns', __CLASS__ . '::product_new_column', 20);
      add_filter('woocommerce_max_webhook_delivery_failures', __CLASS__ . '::overrule_webhook_disable_limit' );
      add_action('manage_product_posts_custom_column', __CLASS__ . '::product_new_column_data', 2);
      add_action('woocommerce_update_product', __CLASS__ . '::handle_save_product');
      add_action('woocommerce_new_product', __CLASS__ . '::handle_save_product');
      add_action('admin_head', __CLASS__ . '::my_custom_style_reachu');
      add_action('wp_ajax_reachu_save_settings', __CLASS__ . '::reachu_save_settings');
      add_action('wp_ajax_nopriv_reachu_save_settings', __CLASS__ . '::reachu_save_settings');
      add_action('wp_ajax_reachu_sync', __CLASS__ . '::reachu_sync');
      add_action('wp_ajax_nopriv_reachu_sync', __CLASS__ . '::reachu_sync');
      add_action('wp_ajax_reachu_sync_finish', __CLASS__ . '::reachu_sync_finish');
      add_action('wp_ajax_nopriv_reachu_sync_finish', __CLASS__ . '::reachu_sync_finish');
      add_action('wp_ajax_reachu_delete_prod', __CLASS__ . '::reachu_delete_prod');
      add_action('wp_ajax_nopriv_reachu_delete_prod', __CLASS__ . '::reachu_delete_prod');
      add_action('wp_trash_post', __CLASS__ . '::handle_trash_post');
      add_action('wp_ajax_logout_reachu', __CLASS__ . '::logout_reachu');
      add_action('wp_ajax_nopriv_logout_reachu', __CLASS__ . '::logout_reachu');
      add_action('admin_enqueue_scripts', __CLASS__ . '::dcms_insertar_js');
      add_action('before_delete_post',  __CLASS__ . '::handle_delete_product_images');
      register_activation_hook(
        __FILE__,
        function () {
          update_option('wc_reachu_currency', '');
        }
      );
      register_deactivation_hook(
        __FILE__,
        function () {
          update_option('wc_reachu_apikey', '');
          update_option('wc_reachu_currency', '');
          update_option('wc_reachu_user', '');
          update_option('wc_reachu_password', '');
          update_option('firebaseUserId', '');
          if (class_exists('OSEWCPHJC_reachuSync') && method_exists('OSEWCPHJC_reachuSync', 'plugin_uninstall') && class_exists('WooCommerce')) {
            OSEWCPHJC_reachuSync::plugin_uninstall();
          }
        }
      );
    }

    public static function remove_webhook()
    {
      self::log('Inicio - remove_webhook');
    
      $data_store = WC_Data_Store::load('webhook');
      $webhooks = $data_store->search_webhooks();

      foreach ($webhooks as $webhook_id) {
        $webhook = wc_get_webhook($webhook_id);
        $nombre_webhook_creado = "Outshifter order.created";
        $nombre_webhook_actualizado = "Outshifter order.updated";

        self::log('Nombre del Webhook: ' . $webhook->get_name());


        if ($webhook->get_name() === $nombre_webhook_creado || $webhook->get_name() === $nombre_webhook_actualizado) {
            self::log('Webhook ' . $webhook->get_name() . ' eliminado.');
            $webhook->delete(true);
        }
      }

      self::log('Fin - remove_webhook');
    }

    public static function remove_api_keys()
    {
      self::log('Inicio - remove_api_keys');

      $descripcion_buscada = "Reachu export";
  
      global $wpdb;
      $tabla_api_keys = $wpdb->prefix . 'woocommerce_api_keys';
      $api_keys = $wpdb->get_results("SELECT key_id, description FROM {$tabla_api_keys}");
  
      foreach ($api_keys as $api_key) {
          if (strpos($api_key->description, $descripcion_buscada) !== false) {
              $wpdb->delete($tabla_api_keys, ['key_id' => $api_key->key_id], ['%d']);
              self::log('Clave API con descripción conteniendo "' . $descripcion_buscada . '" eliminada.');
          }
      }
  
      self::log('Fin - remove_api_keys');

    }

    public static function plugin_uninstall() {
      self::remove_webhook();
      self::remove_api_keys();
  }

    public static function overrule_webhook_disable_limit($number)
    {
      return 999999999999;
    }

    public static function log($message, $level = 'info')
    {
      if (empty(self::$log)) {
        self::$log = wc_get_logger();
      }
      if ($level == 'info') {
        self::$log->info($message, array('source' => 'outshifter-export'));
      }
      if ($level == 'error') {
        self::$log->error($message, array('source' => 'outshifter-export'));
      }
    }

    public static function reachu_save_settings()
    {
      $currency = sanitize_text_field($_POST['currency']);
      self::log('[reachu_save_settings] Saving settings, currency=' . $currency);
      $config = array(
        'currency' => $currency
      );
      self::call('/woo/config', 'PUT', $config);
      update_option('wc_reachu_currency', $currency);
      wp_send_json_success();
    }

    public static function dcms_insertar_js()
    {
      if ( isset( $_GET['post_type'] ) && 'product' === sanitize_text_field( $_GET['post_type'] ) ) {
        wp_register_script('dcms_miscript', OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/js/script.js', array('jquery'), '1', true);
        wp_enqueue_script('dcms_miscript');
        wp_localize_script('dcms_miscript', 'dcms_vars', [
          'ajaxurl' => admin_url('admin-ajax.php'),
          'reachu_nonce' => wp_create_nonce('reachu_sync'),
          'reachu_nonce_finish' => wp_create_nonce('reachu_sync_finish'),
        ]);
      }
      if (isset($_GET['page']) && isset($_GET['tab']) && $_GET['page'] === 'wc-settings' && $_GET['tab'] === 'settings_reachu') {
        $products = wc_get_products(array('limit' => -1, 'return' => 'ids'));
        wp_register_script('dcms_miscript', OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/js/settings-reachu.js', array('jquery'), '1', true);
        wp_enqueue_script('dcms_miscript');
        wp_localize_script('dcms_miscript', 'dcms_vars', [
          'ajaxurl' => admin_url('admin-ajax.php'),
          'reachu_nonce' => wp_create_nonce('reachu_sync'),
          'reachu_nonce_finish' => wp_create_nonce('reachu_sync_finish'),
          'products' => $products
        ]);
        wp_enqueue_style('dcms_mistyle', OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/css/index.css', array(), '1', 'all');
      }
    }

    public static function getAuthorizationUrl($userId)
    {
      $current_path = isset($_SERVER['REQUEST_URI']) ? sanitize_url($_SERVER['REQUEST_URI']) : '';
      $current_paths_array = explode("/wp-admin", $current_path);
      $return_url = site_url('/wp-admin' . $current_paths_array[1]);
      $params = array(
        'app_name' => 'Reachu export',
        'scope' => 'read_write',
        'user_id' => intval($userId),
        'return_url' => $return_url,
        'callback_url' => OSEWCPHJC_API_URL . '/woo/auth/callback-supplier/',
      );
      $queryString = http_build_query($params);
      return site_url() . '/wc-auth/v1/authorize?' . $queryString;
    }

    private static function getProductId($userApiKey, $postId)
    {
      $productIdField = get_post_meta($postId, OSEWCPHJC_FIELD_PRODUCT_ID, true);
      $productId = null;
      $array = json_decode($productIdField, true);
      if (is_array($array)) {
        $found_key = array_search($userApiKey, array_column($array, 'idusr'));
        if ($found_key !== false) {
          $productId = $array[$found_key]['idprod'];
        }
      } else if (is_string($productIdField)) {
        $productId = $productIdField;
      }
      return $productId;
    }

    public static function forceSecureImage($imageUrl)
    {
      $schema = parse_url($imageUrl, PHP_URL_SCHEME);
      if ($schema !== 'https') {
        return str_replace('http://', 'https://', $imageUrl);
      }
      return $imageUrl;
    }

    private static function call($endpoint = '', $method = 'GET', $body = null)
    {
      $url = OSEWCPHJC_API_URL . $endpoint;
      $token = self::getUserApiKey();

      self::log('[call] Calling ' . $method . ', url ' . $url);
      $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json;charset=UTF-8',
        'authorization' => $token
      );
      $args = array(
        'method' => $method,
        'headers' => $headers,
        'timeout' => '10',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'cookies' => array()
      );
      if ($body) {
        $args['body'] = json_encode($body);
        $args['data_format'] = 'body';
      }
      $response = wp_remote_request($url, $args);
      $response_code = wp_remote_retrieve_response_code($response);
      if (is_wp_error($response) || !in_array($response_code, array(200, 201))) {
        $response_message = wp_remote_retrieve_response_message($response);
        self::log('[call] Error ' . $method . ' url ' . $url . ' with code: ' . $response_code . '(' . $response_message . ')', 'error');
        return "Error in response";
      }
      $outputBodyResponse = wp_remote_retrieve_body($response);
      return json_decode($outputBodyResponse);
    }

    private static function verifyWebhookCreated()
    {
      $data_store = WC_Data_Store::load('webhook');
      $webhooks = $data_store->search_webhooks();
      $result = false;
      foreach ($webhooks as $webhook_id) {
        $webhook = wc_get_webhook($webhook_id);
        if ($webhook->get_name() === 'Outshifter order.updated') {
          $result = true;
          break;
        }
      }
      return $result;
    }

    private static function deleteProductByPostId($postId, $userApiKey, $deleteType = null)
    {
      self::log('[deleteProductByPostId] by postId ' . $postId);
      $productId = self::getProductId($userApiKey, $postId);
      $productSqsId = get_post_meta($postId, OSEWCPHJC_FIELD_SQS_ID, true);
      if ($productId) {
        self::log('[deleteProductByPostId] deleting productId ' . $productId . ' by postId ' . $postId . '...');
        if ($deleteType === 'trash') {
          self::call('/api/products/' . $productId, 'DELETE');
        }
        update_post_meta($postId, OSEWCPHJC_FIELD_PRODUCT_ID, '');
        update_post_meta($postId, OSEWCPHJC_FIELD_SQS_ID, '');
        update_post_meta($postId, OSEWCPHJC_FIELD_APIKEY, '');
        update_post_meta($postId, OSEWCPHJC_FIELD_UID, '');
        self::log('[deleteProductByPostId] product ' . $productId . ' deleted in reachu');
      } else if ($productSqsId) {
        update_post_meta($postId, OSEWCPHJC_FIELD_SQS_ID, '');
        update_post_meta($postId, OSEWCPHJC_FIELD_APIKEY, '');
        update_post_meta($postId, OSEWCPHJC_FIELD_UID, '');
      } else {
        self::log('[deleteProductByPostId] not found productId by postId ' . $postId);
      }
    }

    private static function getUserApiKey()
    {
      $userApiKey = get_option('wc_reachu_apikey');

      if ($userApiKey) {
        return $userApiKey;
      } else {
        return '';
      }
    }

    private static function buildProductDto($postId)
    {
      $product = wc_get_product($postId);
      $currency = get_option('wc_reachu_currency');
      self::log('[buildProductDto] Currency to use: ' . $currency);

      $regular_price = $product->get_regular_price();
      $sale_price = $product->get_sale_price();
      $price_to_use = $regular_price;
      $compare_at = '';
      if ($sale_price && $sale_price < $regular_price) {
        $price_to_use = $sale_price;
        $compare_at = $regular_price;
      }

      /******************* IMAGES *********************************/
      $images = array();
      $order = 1;
      $image_id = $product->get_image_id();
      $image_url = '';
      if ($image_id) {
        $image_url = wp_get_attachment_image_url($image_id, 'full');
        $image_url_https = self::forceSecureImage($image_url);
        $images[] = array('order' => 0, "image" => $image_url_https);
      }
      $attachment_ids = $product->get_gallery_image_ids();
      self::log("Fetched attachment IDs: " . implode(', ', $attachment_ids));
      foreach ($attachment_ids as $attachment_id) {
        self::log("Processing attachment ID: " . $attachment_id);
        $image_url = '';
        if ($attachment_id) {
          $image_url = wp_get_attachment_image_url($attachment_id, 'full');
          $attachment_url_https = self::forceSecureImage($image_url);
          $images[] = array('order' => $order, "image" => $attachment_url_https);
          self::log("Added image URL: " . $attachment_url_https);
          $order++;
        } else {
          self::log("No attachment ID found.");
        }
      }
      /********************** INVENTORY ***********************************/
      $variants = array();
      $options = array();
      $optionsEnabled = false;
      $stock = $product->get_stock_quantity();
      $attributes = $product->get_attributes();
      if ($attributes && $product->is_type('variable')) {
        $available_variations = $product->get_available_variations();
        if (!empty($available_variations)) {
          $optionsEnabled = true;
          $countOptions = 1;
          $maxOptions = 3;
          $stock = 0;
          foreach ($attributes as $attribute) {
            $attributeName = $attribute['name'];
            $optionsList = $attribute['options'];
            if ($attribute->is_taxonomy()) {
              $taxonomy = $attribute->get_taxonomy_object();
              $attributeName = $taxonomy->attribute_label;
              $optionsList = [];
              $attribute_values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'all'));
              foreach ($attribute_values as $attribute_value) {
                $optionsList[] = $attribute_value->name;
              }
            }

            $options[] = array(
              "name" => $attributeName,
              "order" => $countOptions,
              "values" => implode(',', $optionsList)
            );

            $countOptions++;
            if ($countOptions > $maxOptions) break;
          }
          foreach ($available_variations as $variation_item) {
            $variationId = $variation_item['variation_id'];
            $variation = new WC_Product_variation($variationId);
            $stock_variation = $variation->get_stock_quantity();
            $stock = $stock + $stock_variation;
            $title = implode('-', $variation->get_attributes());
            $variation_image_array = [];
            $variation_image_id = $variation->get_image_id();
            if ($variation_image_id) {
              $variation_image_url = wp_get_attachment_image_url($variation_image_id, 'full');
              if ($variation_image_url) {
                  $variation_image_url_https = self::forceSecureImage($variation_image_url);
                  $variation_image_array[] = array(
                    "image" => $variation_image_url_https,
                    "order" => 0
                  );
              }
            }
            $variation_regular_price = $variation->get_regular_price();
            $variation_sale_price = $variation->get_sale_price();
            $variation_price_to_use = $variation_regular_price;
            $variation_compare_at = '';
            if ($variation_sale_price && $variation_sale_price < $variation_regular_price) {
              $variation_price_to_use = $variation_sale_price;
              $variation_compare_at = $variation_regular_price;
            }
            $variants[] = array(
              "sku" => $variation->get_sku(),
              "price" => $variation_price_to_use,
              "priceCompareAt" => $variation_compare_at,
              "quantity" => $stock_variation,
              "title" => $title,
              "originId" => $variationId,
              "images" => $variation_image_array,
            );
          }
        }
      }
      $productDto = array(
        "title" => $product->get_title(),
        "description" => $product->get_description(),
        "price" => array(
          "amount" => $price_to_use,
          "compareAt" => $compare_at,
          "currencyCode" => $currency,
        ),
        "origin" => 'WOOCOMMERCE',
        "originId" => $postId,
        "images" => $images,
        "quantity" => $stock,
        "barcode" => "",
        "sku" => $product->get_sku(),
        "optionsEnabled" => $optionsEnabled,
        "options" => $options,
        "variants" => $variants,
        "currency" => $currency,
        "from" => 'WOOCOMMERCE',
      );
      if ($product->get_weight() !== '') $productDto["weight"] = $product->get_weight();
      if ($product->get_width() !== '') $productDto["width"] = $product->get_width();
      if ($product->get_height() !== '') $productDto["height"] = $product->get_height();
      if ($product->get_length() !== '') $productDto["depth"] = $product->get_length();

      return $productDto;
    }

    public static function add_update_product($post_ids, $userApiKey) {
      $batchedProducts = [];
      foreach($post_ids as $post_id) {
        $postDto = self::buildProductDto($post_id);
        $batchedProduct = [
          "product" => $postDto
        ];
        $batchedProducts[] = $batchedProduct;
      }
      $payload = [
        "products" => $batchedProducts
      ];
      $productCreated = self::call('/api/products/create-sqs', 'POST', $payload);
      if ($productCreated && isset($productCreated->messageId)) {
        $messageId = $productCreated->messageId;
        foreach($post_ids as $post_id) {
          update_post_meta($post_id, OSEWCPHJC_FIELD_SQS_ID, $messageId);
          update_post_meta($post_id, OSEWCPHJC_FIELD_APIKEY, $userApiKey);
          self::log('[add_update_product] Product batch with MessageId ' . $messageId . ' includes post ' . $post_id);
        }
      } else {
        foreach($post_ids as $post_id) {
          self::log('[add_update_product] Error creating products from post ' . $post_id, 'error');
        }
      }
    }

    private static function areOptionsEqual($currentOptions, $initialOptions) {
      $currentOptions = is_object($currentOptions) ? get_object_vars($currentOptions) : $currentOptions;
      $initialOptions = is_object($initialOptions) ? get_object_vars($initialOptions) : $initialOptions;
  
      if (count($currentOptions) != count($initialOptions)) {
        return false;
      }
  
      foreach ($currentOptions as $index => $currentOption) {
        $currentOption = is_object($currentOption) ? get_object_vars($currentOption) : $currentOption;
        
        if (!isset($initialOptions[$index])) {
          return false;
        }

        $initialOption = is_object($initialOptions[$index]) ? get_object_vars($initialOptions[$index]) : $initialOptions[$index];

        $currentValues = is_array($currentOption['values']) ? implode(',', $currentOption['values']) : $currentOption['values'];
        $initialValues = is_array($initialOption['values']) ? implode(',', $initialOption['values']) : $initialOption['values'];

        if ($currentOption['name'] !== $initialOption['name'] ||
          $currentOption['order'] !== $initialOption['order'] ||
          $currentValues !== $initialValues) {
          return false;
        }
      }
  
      return true;
    }

    private static function isMainPriceChanged($currentPrice, $originalPrice) {
      $currentPrice = is_object($currentPrice) ? get_object_vars($currentPrice) : $currentPrice;
      $originalPrice = is_object($originalPrice) ? get_object_vars($originalPrice) : $originalPrice;
  
      $floatTolerance = 0.01;
  
      $currencyCodeMatches = isset($currentPrice['currencyCode'], $originalPrice['currencyCode']) &&
                              $currentPrice['currencyCode'] === $originalPrice['currencyCode'];
  
      if (!$currencyCodeMatches) {
          self::log("Currency codes do not match.");
          return ['amountChanged' => false, 'compareAtChanged' => false];
      }
  
      $amountChanged = isset($currentPrice['amount'], $originalPrice['amount']) &&
                       abs(floatval($currentPrice['amount']) - floatval($originalPrice['amount'])) > $floatTolerance;
  
      $compareAtChanged = isset($currentPrice['compareAt'], $originalPrice['compareAt']) &&
                          abs(floatval($currentPrice['compareAt']) - floatval($originalPrice['compareAt'])) > $floatTolerance;
  
      return ['amountChanged' => $amountChanged, 'compareAtChanged' => $compareAtChanged];
    }

    private static function getChangedValues($currentValues, $initialValues) {
      $changes = [];
      $initialValuesArray = is_object($initialValues) ? get_object_vars($initialValues) : $initialValues;

      foreach ($currentValues as $key => $currentValue) {
        if (!array_key_exists($key, $initialValuesArray)) {
          $changes[$key] = $currentValue;
          continue;
        }

        $initialValue = $initialValuesArray[$key];

        if ($key === 'variants') {
          $variantChanges = self::compareVariants($currentValue, $initialValue);
          if (!empty($variantChanges)) {
            $changes[$key] = $variantChanges;
          }
        } elseif ($key === 'images') {
          $imageChanges = self::compareImages($currentValue, $initialValue);
          if (!empty($imageChanges)) {
            $changes[$key] = $imageChanges;
          }
        } elseif ($key === 'options') {
          if (!self::areOptionsEqual($currentValue, $initialValue)) {
            $changes[$key] = $currentValue;
          }
        } elseif ($key === 'price') {
          $originalPrice = isset($initialValuesArray['originalPrice']) ? $initialValuesArray['originalPrice'] : null;
          $priceChange = self::isMainPriceChanged($currentValue, $originalPrice);

          if ($priceChange['amountChanged'] || $priceChange['compareAtChanged']) {
            $priceUpdate = ['currencyCode' => $currentValue['currencyCode']];
            if ($priceChange['amountChanged']) {
              $priceUpdate['amount'] = $currentValue['amount'];
            }
            if ($priceChange['compareAtChanged']) {
              $priceUpdate['compareAt'] = $currentValue['compareAt'];
            }
            $changes[$key] = $priceUpdate;
          }
        } elseif (is_array($currentValue) && is_array($initialValue)) {
          $nestedChanges = self::getChangedValues($currentValue, $initialValue);
          if (!empty($nestedChanges)) {
            $changes[$key] = $nestedChanges;
          }
        } else if ($currentValue !== $initialValue) {
          $changes[$key] = $currentValue;
        }
      }

      if (isset($changes['variants'])) {
        foreach ($changes['variants'] as &$variant) {
          if (isset($variant['images']) && is_array($variant['images'])) {
            foreach ($variant['images'] as &$image) {
              if (isset($image['image'])) {
                $image['url'] = $image['image'];
                //unset($image['image']);
              }
            }
          }
        }
      }

      foreach (['originId', 'currency', 'from'] as $unnecessaryField) {
        if (array_key_exists($unnecessaryField, $changes)) {
          unset($changes[$unnecessaryField]);
        }
      }

      return $changes;
    }

    private static function compareImages($currentImages, $initialImages) {
      if (count($currentImages) != count($initialImages)) {
        return $currentImages;
      }
  
      foreach ($currentImages as $index => $currentImage) {
        $initialImage = $initialImages[$index] ?? null;
        if (!$initialImage || !self::isImageEqual($currentImage, $initialImage)) {
          return $currentImages;
        }
      }
  
      return [];
   }
  
  
    private static function isImageEqual($image1, $image2) {
      $image1 = is_object($image1) ? get_object_vars($image1) : $image1;
      $image2 = is_object($image2) ? get_object_vars($image2) : $image2;
  
      $url1 = $image1['image'] ?? $image1['url'] ?? '';
      $url2 = $image2['image'] ?? $image2['url'] ?? '';

      if (empty($url1) || empty($url2)) {
        return true;
      }
  
      return $url1 === $url2;
    }

    private static function compareVariants($wooVariants, $reachuVariants) {
      $changes = [];
      $variantChanged = false;
  
      foreach ($wooVariants as $index => $wooVariant) {
        $reachuVariant = self::findVariantById($reachuVariants, $wooVariant['originId'] ?? null);

        if (!empty($wooVariant['images']) && is_array($wooVariant['images'])) {
          foreach ($wooVariant['images'] as $imgIndex => $imageData) {
            if (isset($imageData['image'])) {
              $wooVariant['images'][$imgIndex]['url'] = $imageData['image'];
              unset($wooVariant['images'][$imgIndex]['image']);
            }
          }
        }

        if ($reachuVariant && self::isVariantChanged($wooVariant, $reachuVariant)) {
          $variantChanged = true;
          break;
        }
  
      }
  
      if ($variantChanged) {
        $changes = $wooVariants;
      }
  
      return $changes;
    }
  

    private static function findVariantById($variants, $originId) {
      foreach ($variants as $variant) {
        $variant = is_object($variant) ? get_object_vars($variant) : $variant;
        if (isset($variant['originId']) && $variant['originId'] == $originId) {
          return $variant;
        }
      }
      return null;
    }

    private static function isVariantChanged($wooVariant, $reachuVariant) {
      $wooVariant = is_object($wooVariant) ? get_object_vars($wooVariant) : $wooVariant;
      $reachuVariant = is_object($reachuVariant) ? get_object_vars($reachuVariant) : $reachuVariant;
  
      if (isset($reachuVariant['originalPrice']) && is_object($reachuVariant['originalPrice'])) {
        $reachuVariant['originalPrice'] = get_object_vars($reachuVariant['originalPrice']);
      }
      if (isset($reachuVariant['images'][0]) && is_object($reachuVariant['images'][0])) {
        $reachuVariant['images'][0] = get_object_vars($reachuVariant['images'][0]);
      }
  
      $floatTolerance = 0.01;
  
      $priceChanged = abs(floatval($wooVariant['price']) - floatval($reachuVariant['originalPrice']['amount'])) > $floatTolerance;
      $priceCompareAtChanged = abs(floatval($wooVariant['priceCompareAt']) - floatval($reachuVariant['originalPrice']['compareAt'])) > $floatTolerance;
      $quantityChanged = $wooVariant['quantity'] !== $reachuVariant['quantity'];
      $titleChanged = $wooVariant['title'] !== $reachuVariant['title'];
      $originIdChanged = (string)$wooVariant['originId'] !== (string)$reachuVariant['originId'];
      $imageChanged = !self::isImageEqual($wooVariant['images'][0] ?? [], $reachuVariant['images'][0] ?? []);
      $skuChanged = $wooVariant['sku'] !== $reachuVariant['sku'];
  
      if ($priceChanged || $priceCompareAtChanged || $quantityChanged || $titleChanged || $originIdChanged || $imageChanged || $skuChanged) {
        self::log("Variant change detected due to: " . implode(", ", array_filter([
          $priceChanged ? "Price" : null,
          $priceCompareAtChanged ? "PriceCompareAt" : null,
          $quantityChanged ? "Quantity" : null,
          $titleChanged ? "Title" : null,
          $originIdChanged ? "OriginId" : null,
          $imageChanged ? "Image" : null,
          $skuChanged ? "SKU" : null,
        ])));
        return true;
      }
  
      return false;
    }
  
    public static function reachu_update_product($post_id, $userApiKey) {
      self::log('[update_product] Handle update post ' . $post_id . ' by authentication: ' . $userApiKey);
      $reachuOrigin = get_post_meta($post_id, 'OSEWCPHJC_reachu_ORIGIN', true);
  
      if ($reachuOrigin) {
        self::log('[update_product] No processable. The product\'s origin is reachu ' . $reachuOrigin);
      } else {
        $reachuProductId = self::getProductId($userApiKey, $post_id);
        $currentWooData = self::buildProductDto($post_id);
  
        if ($reachuProductId) {
          $product = wc_get_product($post_id);
          $currentReachuData = self::call('/api/products/' . $reachuProductId);
          $dataToUpdate = self::getChangedValues($currentWooData, $currentReachuData);
  
          if (isset($dataToUpdate['variants']) && $currentReachuData) {
            $externalVariants = isset($currentReachuData->variants) ? (array) $currentReachuData->variants : [];
            foreach ($dataToUpdate['variants'] as &$variant) {
              foreach ($externalVariants as $externalVariant) {
                $externalVariant = (array) $externalVariant;
                if ($variant['originId'] == $externalVariant['originId']) {
                  $variant['id'] = $externalVariant['id'];
                }
              }
            }
            unset($variant);
          }
  
          if (!empty($dataToUpdate)) {
            self::log('[add_update_product] Updating product ' . $reachuProductId . ' from post ' . $post_id . '...');
            self::log('[add_update_product] Data to update for product 16:14' . $reachuProductId . ': ' . print_r($dataToUpdate, true));
            $updated = self::call('/api/products/' . $reachuProductId, 'PUT', $dataToUpdate);
            if ($updated) {
              self::log('[add_update_product] Product ' . $reachuProductId . ' updated with changed fields');
            } else {
              self::log('[add_update_product] Error updating product ' . $reachuProductId . ' from post ' . $post_id, 'error');
            }
          } else {
            self::log('[add_update_product] No changes detected for product ' . $reachuProductId . '. No update dispatched.');
          }
        }
      }
    }

    public static function reachu_sync()
    {
      if (isset($_POST['reachu_nonce']) && isset($_POST['id_posts'])) {
        if (wp_verify_nonce($_POST['reachu_nonce'], 'reachu_sync')) {
          $post_ids = isset($_POST['id_posts']) ? array_map('absint', array_map('sanitize_text_field', $_POST['id_posts'])) : [];

          if (!empty($post_ids)) {
            self::log('[reachu_sync] init by postIds ' . implode(',', $post_ids));

            $userApiKey = self::getUserApiKey();
            self::add_update_product($post_ids, $userApiKey);

            wp_send_json_success();
          } else {
            self::log('[reachu_sync] Error: No post IDs provided');
            wp_send_json_error();
          }
        } else {
          self::log('[reachu_sync] Error: Nonce not verified');
          wp_send_json_error();
        }
      } else {
        self::log('[reachu_sync] Error: Nonce or post IDs not set');
        wp_send_json_error();
      }
    }

    public static function reachu_sync_finish()
    {
      self::log('[reachu_sync_finish] call upgrade first sync');
      self::call('/api/users/me/finish-sync?origin=WOOCOMMERCE', 'PUT');
      self::log('[reachu_sync_finish] finish call first sync');
    }

    public static function reachu_delete_prod()
    {
      self::log('==> reachu_delete_prod called');

      if (isset($_POST['reachu_nonce']) && isset($_POST['id_posts'])) {
        if (wp_verify_nonce($_POST['reachu_nonce'], 'reachu_sync')) {
          $post_ids = isset($_POST['id_posts']) ? array_map('absint', array_map('sanitize_text_field', $_POST['id_posts'])) : [];

          if (!empty($post_ids)) {
            $userApiKey = self::getUserApiKey();
            if ($userApiKey !== '') {
              $productIds = [];

              foreach ($post_ids as $post_id) {
                $productId = self::getProductId($userApiKey, $post_id);
                if ($productId !== null) {
                    $productIds[] = $productId;
                    self::deleteProductByPostId($post_id, $userApiKey);
                }
              }
              $idsString = implode(',', $productIds);
              $apiUrl = '/api/products?ids=' . $idsString;
              $apiResponse = self::call($apiUrl, 'DELETE');
              if ($apiResponse['status'] === 'success') {
                self::log('[reachu_delete_prod] All products deleted');
              } else {
                self::log('[reachu_delete_prod] Error deleting products');
              }
            }
          } else {
            self::log('[reachu_delete_prod] Error: No post IDs provided');
          }
        } else {
          self::log('[reachu_delete_prod] Error: Nonce not verified');
        }
      } else {
        self::log('[reachu_delete_prod] Error: Nonce or post IDs not set');
      }

      die();
    }

    public static function handle_delete_product_images ( $postId )
    {
      $reachuOrigin = get_post_meta($postId, OSEWCPHJC_reachu_ORIGIN, true);

      if ($reachuOrigin) {

        $product = wc_get_product( $postId );

        $featured_image_id = $product->get_image_id();
        $image_galleries_id = $product->get_gallery_image_ids();

        if( !empty( $featured_image_id ) ) {
          wp_delete_attachment( $featured_image_id );
        }

        if( !empty( $image_galleries_id ) ) {
            foreach( $image_galleries_id as $single_image_id ) {
              wp_delete_attachment( $single_image_id );
            }
        }

      }
    }

    public static function handle_trash_post($postId)
    {
      self::log('[handle_trash_post] by post ' . $postId);
      $userApiKey = self::getUserApiKey();
      if ($userApiKey !== '') {
        $reachuOrigin = get_post_meta($postId, OSEWCPHJC_reachu_ORIGIN, true);
        if ($reachuOrigin) {
          $product = wc_get_product($postId);
          $featured_image_id = $product->get_image_id();
          $image_galleries_id = $product->get_gallery_image_ids();
          if (!empty( $featured_image_id )) {
            wp_delete_attachment( $featured_image_id );
          }
          if ($image_galleries_id) {
            foreach ($image_galleries_id as $single_image_id) {
              if (!empty( $single_image_id )) {
                wp_delete_attachment( $single_image_id );
              }
            }
          }
        }
        self::deleteProductByPostId($postId, $userApiKey, "trash");
      }
    }

    public static function logout_reachu()
    {
      self::log('[logout_reachu] call disconnect reachu');
      update_option('wc_reachu_apikey', '');
      update_option('wc_reachu_user', '');
      update_option('wc_reachu_password', '');
      update_option('firebaseUserId', '');

      self::plugin_uninstall();

      self::log('[logout_reachu] disconnect reachu success');
      if (wp_redirect('admin.php?page=wc-settings&tab=settings_reachu')) {
        exit;
      }
    }

    public static function handle_save_product($postId)
    {
      $userApiKey = self::getUserApiKey();
      if (empty($userApiKey)) {
        self::log("No API key found for Product {$postId}. Skipping sync.");
        return;
      }
      $reachuProductId = self::getProductId($userApiKey, $postId);
      $product = wc_get_product($postId);
      if ($reachuProductId && $product && $product->is_type('variable')) {
        sleep(2);
      }
      $updating_post_id = 'update_product_' . $postId;
      $updating_post_id = 'update_product_' . $postId;
      if (false === ($updating_post = get_transient($updating_post_id))) {
          self::reachu_update_product($postId, $userApiKey);
          set_transient($updating_post_id, $postId, 5);
      }
    }

    public static function my_custom_style_reachu()
    {
      echo '
        <style type="text/css">
          .reachu_icon_column {width:20px;display:block;margin:0 auto !important;}
          .reachu_icon_settings {width:30px;display:block;margin:1em auto 0;margin-top:50px}
          .manage-column.column-col_reachu_sync{width:75px!important;}
          #reachu_proccess_bulkaction{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:none;}
          #reachu_proccess_bulkaction.visible{display:flex!important;align-items:center;justify-content:center}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_content{background:white;width:300px;text-align:center;border-radius:10px;padding:1em;box-sizing:border-box;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_logo{background-image:url(' . esc_url(OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/img/icon.svg') . ');width:50px;height:40px;background-repeat:no-repeat;background-position:center;background-size:contain;margin:0 auto;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_info{width:180px;text-align:left;margin:1em auto;display:flex;justify-content:space-between;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_info p{margin:0;padding:0;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_progress_bar{width:200px;margin:1em auto 0;border:2px solid #1864FF;box-sizing:border-box;height:1em;position:relative;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_progress_bar .reachu_proccess_bulkaction_progress{background:#1864FF;position:absolute;top:0;left:0;width:0;height:100%;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_finish{display:none;flex-direction: column;}
          #reachu_proccess_bulkaction .reachu_proccess_bulkaction_finish.visible{display:flex!important;}
          .reachu-spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border-top-color: #000;
            animation: reachuspin 1s ease-in-out infinite;
            margin: 0 auto;
          }
          @keyframes reachuspin {
            0% {
              transform: rotate(0deg);
            }
            100% {
              transform: rotate(360deg);
            }
          }
        </style>';
    }

    public static function product_new_column($columns)
    {
      $columns['col_reachu_sync'] = esc_html__('Reachu', 'woocommerce');
      return $columns;
    }

    public static function product_new_column_data($column)
    {
      global $post;
      if ($column == 'col_reachu_sync') {
        $userApiKey = self::getUserApiKey();
        if ($userApiKey !== '') {
          $productId = self::getProductId($userApiKey, $post->ID);
          $sqsId = get_post_meta($post->ID, OSEWCPHJC_FIELD_SQS_ID, true);
          if ($productId) {
            echo '<img class="reachu_icon_column" src="' . esc_url(OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/img/icon.svg') . '">';
          } else if ($sqsId) {
            if (is_string($sqsId)) {
              echo '<div>Syncing...</div>';
            } else {
              echo '<div>Error</div>';
            }
          }
        }
      }
    }

    private static function deleteProductoUsr($apiKey, $arrayUsrProd)
    {
      $retorno = false;
      $array = json_decode($arrayUsrProd, true);

      if (is_array($array)) {
        $found_key = array_search($apiKey, array_column($array, 'idusr'));
        if ($found_key !== false) {
          unset($array[$found_key]);
          $retorno = json_encode($array);
        }
      }

      return $retorno;
    }

    public static function add_settings_tab($settings_tabs)
    {
      $settings_tabs['settings_reachu'] = __('Reachu', 'woocommerce-settings-reachu');
      return $settings_tabs;
    }

    public static function generate_option_currency($currency)
    {
      return '<li>' .
        '<div class="option">' .
        $currency .
        '</div>' .
        '</li>';
    }

    public static function settings_tab()
    {
      $apiKey = self::getUserApiKey();
      $strLogUser = '';
      $currency = get_option('wc_reachu_currency');
      echo '
        <style type="text/css">
            .wrap.woocommerce{ background:white!important;padding-bottom:2em; }
            #mainform h2{ margin:1em 0 0!important;text-align:center; }
            #wc_reachu_section-description p{ margin:0 0 1em!important;text-align:center; }
            #mainform .form-table{ width:auto;margin:2em auto 0; max-width:530px;}
            #mainform .form-table th { vertical-align:middle; }
            #mainform .submit{ text-align: center;}
            #mainform .submit button { margin-bottom: 100px;}
            #mainform .form-table tbody tr:nth-child(2) th { padding-top: 0px; }
        </style>
        ';

      if ($currency !== "") {
        if ( $apiKey !== '' ) {
          $loggedUser = self::call('/catalog/users/me');

        if (isset($loggedUser->id)) {
          $isWebhookCreated = self::verifyWebhookCreated();
          echo '
            <style type="text/css">
                .wp-core-ui .button-primary { display:none; }
                .reachu_icon_check_settings { display:block;margin: 1vh auto;width:35px;margin-bottom:40px;margin-top:25px; }
                .abtnLogout_reachu { display:inline-block;margin-top:25px; }
                .reachu-button { background:#1864FF;border:none;padding:12px 24px;border-radius:10px;box-shadow:none; }
                .reachu-button-disabled { background: rgb(24,100,255,0.6); }
                .loading { display: none; }
            </style>
          ';

            if ($isWebhookCreated === true) {
              if (isset($loggedUser->woo) && isset($loggedUser->woo->currency)) {
                if ($currency !== $loggedUser->woo->currency) {
                  $config = array(
                    'currency' => $currency
                  );
                  self::call('/woo/config', 'PUT', $config);
                }
              }
              $strLogUser = '
                <h2>' . __('Your store is conected', 'woocommerce') . '</h2>
                <div style="display:block;text-align:center;padding:0.5em 0;">The conection between the Reachu<br />Platform and your store enables</div>
                <img class="reachu_icon_check_settings" src="' . OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/img/check.png' . '">
                <div style="display:block;text-align:center;margin-bottom:20px">';
                  if ($currency !== '') {
                    $strLogUser .= '
                    <a id="sync-all-button" class="reachu-button" style="text-decoration:none;color:white;cursor:pointer">
                      Continue
                    </a>';
                  } else {
                    $strLogUser .= '
                      <p style="padding-bottom: 15px;">Please select a currency before continuing.</p>
                      <a id="sync-all-button-disabled" class="reachu-button reachu-button-disabled" style="text-decoration:none;color:white;cursor:pointer">
                        Continue
                      </a>';
                  }
                $strLogUser .= '</div>
                <div style="text-align:center;">
                </div>
                <div style="display:block;text-align:center;padding:0.5em 0;">
                  <span style="">'. $loggedUser->username .' |</span>
                  <span style=""> '. $currency .' |</span>
                  <a class="abtnLogout_reachu" href="admin-ajax.php?action=logout_reachu">&#8594; Logout</a>
                </div>';
            } else {
              $authorization_url = self::getAuthorizationUrl($loggedUser->id);
              $strLogUser = '
                <div style="display:block;text-align:center;margin-top:50px">
                  <a class="reachu-button" style="text-decoration:none;color:white" href="' . $authorization_url . '">
                    Connect
                  </a>
                </div>
              ';
            }
          } else {
            echo '<p style="display:block;text-align:center;padding:0.5em 0;font-size:1.5em;background:white;border:2px solid red;box-sizing:border-box;">
              <strong style="color:red;">' . __('API Key is not correct', 'woocommerce') . '</strong>
            </p>';
          }
        }
      } else if ($apiKey !== "") {
        echo '<p style="display:block;text-align:center;padding:0.5em 0;font-size:1.5em;background:white;border:2px solid red;box-sizing:border-box;">
          <strong style="color:red;">' . __('Please select a currency.', 'woocommerce') . '</strong>
        </p>';
      }

      echo '<img class="reachu_icon_settings" src="' . esc_url(OSEWCPHJC_PLUGIN_FOLDER . OSEWCPHJC_NAME_FOLDER . '/img/icon.svg') . '">';

      if ($strLogUser === '') {
        woocommerce_admin_fields(self::get_settings());
        echo ('
              <div style="position: absolute;bottom: 130px;text-align: center;width: 100%;">
                <p>
                  Do not have an account yet?
                  <a
                    href="https://app.reachu.com/business-signup"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    Sign up
                  </a>
                <p>
              </div>
            ');
      } else {
        echo $strLogUser;
      }
    }

    public static function update_settings()
    {
      woocommerce_update_options(self::get_settings());
    }

    public static function fetch_currencies_for_settings() {
      $response = self::call('/api/currencies');  
      $options = array('' => '--');  
      if (is_array($response)) {
        foreach ($response as $currency) {
          if (isset($currency->enabled) && $currency->enabled == 1) {
            $options[$currency->currency_code] = $currency->currency_code;
          }
        }
      }
      return $options;
    }

    public static function get_settings()
    {
      $settings = array(
        'section_title' => array(
          'name' => __('Welcome to Reachu', 'woocommerce-settings-reachu'),
          'type' => 'title',
          'desc' => 'Create your Sales Network and Grow Exponentially',
          'id' => 'wc_reachu_section'
        ),
        'apikey-reachu' => array(
          'name' => __('API Key', 'woocommerce-settings-reachu'),
          'type' => 'text',
          'desc' => __('', 'woocommerce-settings-reachu'),
          'id' => 'wc_reachu_apikey'
        ),
        'currency' => array(
          'name' => __('Currency', 'woocommerce-settings-reachu'),
          'type' => 'select',
          'desc' => __('Choose your default currency', 'woocommerce-settings-reachu'),
          'id' => 'wc_reachu_currency',
          'default' => '',
          'options' => self::fetch_currencies_for_settings(),
          'class' => 'reachu-currency-select',
        ),
        'section_end' => array(
          'type' => 'sectionend',
          'id' => 'wc_reachu_section'
        )
      );
      return apply_filters('wc_reachu_settings', $settings);
    }

    public static function enviar_productos($bulk_actions)
    {
      $bulk_actions['reachu_sync'] = 'Reachu Sync';
      $bulk_actions['reachu_delete_prod'] = 'Delete from Reachu';
      return $bulk_actions;
    }
  }

  OSEWCPHJC_reachuSync::init();
}
