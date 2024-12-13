<?php

/*
Plugin Name: PluginSync
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: Export and import plugin data, including installation and activation status.
Version: 1.0
Author: Knomic
Author URI: https://example.com
License: GPL2
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class PluginSync {
	public function __construct() {
		add_action('admin_menu', [$this, 'create_admin_menu']);
		add_action('admin_post_export_plugins', [$this, 'export_plugins']);
		add_action('wp_ajax_pluginsync_export', [$this, 'ajax_export_plugins']);
		add_action('wp_ajax_pluginsync_import', [$this, 'ajax_import_plugins']);
		add_action('pluginsync_scheduled_task', [$this, 'process_scheduled_plugins']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	public function create_admin_menu() {
		add_menu_page(
			'PluginSync',
			'PluginSync',
			'manage_options',
			'pluginsync',
			[$this, 'settings_page'],
			'dashicons-admin-plugins'
		);
	}

	public function settings_page() {
		?>
        <div class="wrap">
            <h1>PluginSync Settings</h1>
            <button id="pluginsync-export" class="button-primary">Export Plugins</button>
            <hr>
            <input type="file" id="pluginsync-import-file" accept=".json">
            <button id="pluginsync-import" class="button-primary">Import Plugins</button>
        </div>
		<?php
	}

	public function enqueue_scripts($hook) {
		if ($hook !== 'toplevel_page_pluginsync') {
			return;
		}

		wp_enqueue_script('pluginsync-script', plugins_url('js/pluginsync.js', __FILE__), ['jquery'], '1.0.0', true);
		wp_localize_script('pluginsync-script', 'pluginsyncAjax', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('pluginsync_nonce'),
		]);
	}

	public function ajax_export_plugins() {
		check_ajax_referer('pluginsync_nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You are not allowed to perform this action.'));
		}

		$plugins = get_plugins();
		$export_data = [];
		foreach ($plugins as $file => $data) {
            $slug = dirname($file);
            $name = $data['Name'];

            if ('.' == $slug) $slug = sanitize_title($name);
			$export_data[] = [
				'name' => $name,
				'version' => $data['Version'],
				'active' => is_plugin_active($file),
				'slug' => $slug,
			];
		}


		wp_send_json_success($export_data);
	}

	public function ajax_import_plugins() {
		check_ajax_referer('pluginsync_nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You are not allowed to perform this action.'));
		}

		if (isset($_FILES['file']['tmp_name'])) {
			$file_data = file_get_contents($_FILES['file']['tmp_name']);
			$plugins = json_decode($file_data, true);

			if (is_array($plugins)) {
				update_option('pluginsync_scheduled_plugins', $plugins);

				if (!wp_next_scheduled('pluginsync_scheduled_task')) {
					wp_schedule_single_event(time() + 30, 'pluginsync_scheduled_task');
				}

				wp_send_json_success(__('Plugins scheduled for installation.'));
			}
		}

		wp_send_json_error(__('Invalid file uploaded.'));
	}

	public function process_scheduled_plugins() {
		$plugins = get_option('pluginsync_scheduled_plugins', []);

        error_log(print_r($plugins, true));

		if (!empty($plugins)) {
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php'; // Add this line to include filesystem credentials

			// Silent skin to prevent any output or interaction
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
			$skin = new Automatic_Upgrader_Skin();

			$upgrader = new Plugin_Upgrader($skin);

			foreach ($plugins as $index => $plugin) {
				if (isset($plugin['slug'])) {
					$plugin_slug = $plugin['slug'];

                    error_log('Processing the plugin: ' .$plugin['name']);

					// Check if plugin is already installed
					$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
					$plugin_file = $plugin_path . '/' . $plugin_slug . '.php';

					if (!file_exists($plugin_file)) {
						// Try to get plugin info from WordPress repository
						$install_info = plugins_api('plugin_information', ['slug' => $plugin_slug]);

						if ($install_info && !is_wp_error($install_info)) {
							// Use silent skin for installation
							$result = $upgrader->install($install_info->download_link);

							if (is_wp_error($result)) {
								error_log(sprintf(
									'Failed to install plugin "%s": %s',
									isset($plugin['name']) ? $plugin['name'] : $plugin_slug,
									$result->get_error_message()
								));
								continue;
							}
						} else {
							// Plugin not found in WordPress repository
							error_log(sprintf(
								'Plugin "%s" could not be found in WordPress repository. This might be a custom or premium plugin.',
								isset($plugin['name']) ? $plugin['name'] : $plugin_slug
							));

							unset($plugins[$index]);
							update_option('pluginsync_scheduled_plugins', $plugins);
							continue;
						}
					}

					// Check activation status
					if ($plugin['active'] && !is_plugin_active($plugin_slug . '/' . $plugin_slug . '.php')) {
						$activation_result = activate_plugin($plugin_slug . '/' . $plugin_slug . '.php');

						if (is_wp_error($activation_result)) {
							error_log(sprintf(
								'Failed to activate plugin "%s": %s',
								isset($plugin['name']) ? $plugin['name'] : $plugin_slug,
								$activation_result->get_error_message()
							));
						}
					}

					unset($plugins[$index]);
					update_option('pluginsync_scheduled_plugins', $plugins);
					break; // Process one plugin per schedule run
				}
			}
		}

		if (!empty($plugins)) {
			wp_schedule_single_event(time() + 30, 'pluginsync_scheduled_task');
		}
	}
}

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('pluginsync_scheduled_task');
});

new PluginSync();

