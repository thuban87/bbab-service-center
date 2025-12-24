# BBAB Core

Admin Command Center plugin for Brad's Bits and Bytes Service Center.

## Description

This plugin provides "Brad's Workbench" - a centralized admin dashboard for managing the BBAB Service Center system. It displays key performance indicators and provides quick access to:

- Open Service Requests
- Active Projects
- Pending Invoices

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Pods plugin (for CPT management)
- Existing BBAB Service Center snippets (via WPCode)

## Installation

1. Clone or copy this plugin to `wp-content/plugins/bbab-core/`
2. Activate the plugin through the WordPress admin
3. Access "Brad's Workbench" from the admin menu (below Dashboard)

## Architecture

This plugin is **additive** - it reads from existing CPTs managed by Pods and calls existing snippet functions. It does not migrate or duplicate existing functionality.

### Directory Structure

```
bbab-core/
├── bbab-core.php           # Main plugin file
├── uninstall.php           # Cleanup on uninstall
├── includes/               # Core classes
│   ├── class-loader.php    # Autoloader
│   ├── class-activator.php
│   ├── class-deactivator.php
│   └── class-bbab-core.php
├── admin/                  # Admin functionality
│   ├── class-admin.php
│   ├── class-workbench.php
│   ├── class-cache.php
│   ├── css/
│   ├── js/
│   └── partials/
└── public/                 # Future: frontend assets
```

## Development

- Local environment: Local by Flywheel
- Local URL: `bradsbitsandbytes.local`

## Version History

### 1.0.0
- Initial release
- Brad's Workbench dashboard with KPI boxes
- Transient caching system
- Cache invalidation hooks

## Author

Brad Wales - [Brad's Bits and Bytes](https://bradsbitsandbytes.com)
