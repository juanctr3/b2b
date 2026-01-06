<?php
/**
 * Plugin Name: SMS B2B Platform (Final)
 * Description: Sistema de Licitaciones, Marketplace de Leads y Pagos por Créditos.
 * Version: 7.0
 * Author: Tu Asistente IA
 */

if (!defined('ABSPATH')) exit;

define('SMS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Cargar módulos
require_once SMS_PLUGIN_DIR . 'includes/db-init.php';
require_once SMS_PLUGIN_DIR . 'includes/admin-panel.php';
require_once SMS_PLUGIN_DIR . 'includes/frontend-logic.php';
require_once SMS_PLUGIN_DIR . 'includes/provider-area.php';
require_once SMS_PLUGIN_DIR . 'includes/webhook.php';

require_once SMS_PLUGIN_DIR . 'includes/unlock-logic.php'; // <--- AGREGA ESTA LÍNEA

register_activation_hook(__FILE__, 'sms_install_tables');