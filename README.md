# Priority Ticket Payment

**A comprehensive WordPress plugin for managing priority ticket submissions with payment integration for WooCommerce and Awesome Support.**

[![WordPress](https://img.shields.io/badge/WordPress-5.0+-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## 🚀 **Features**

### **Core Functionality**
- **Multi-tier Priority System**: A (Premium), B (Standard), C (Free) ticket tiers
- **Automatic Payment Processing**: Integration with WooCommerce for paid support tiers
- **Awesome Support Integration**: Seamless ticket creation with proper priority assignment
- **Elementor Pro Forms**: Direct integration with Elementor Pro form submissions
- **Agent Assignment**: Automatic assignment based on coach selection
- **File Attachments**: Support for file uploads (paid tiers only)

### **Payment & E-commerce**
- **WooCommerce Integration**: Automatic product creation and order processing
- **Auto-completion**: Orders are automatically marked as completed after payment
- **Dynamic Pricing**: Configurable pricing for different priority tiers
- **Order Tracking**: Full order-to-ticket lifecycle tracking

### **Ticket Management**
- **Priority Assignment**: Maps to Awesome Support priority taxonomy (a-ticket, b-ticket, c-ticket)
- **Custom Metadata**: Rich ticket metadata for enhanced tracking
- **Form Data Processing**: Intelligent form field mapping and validation
- **Placeholder Filtering**: Removes placeholder values from coach assignments

### **Administration**
- **Submission Management**: Complete admin interface for viewing and managing submissions
- **Manual Ticket Creation**: Create tickets manually for completed submissions
- **Cleanup Tools**: Automated cleanup of old pending submissions
- **Debug Logging**: Comprehensive logging for troubleshooting

## 📋 **Requirements**

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **WooCommerce**: Latest version (for payment processing)
- **Awesome Support**: Latest version (for ticket management)
- **Elementor Pro**: Latest version (for form integration)

## 🛠 **Installation**

1. Upload the plugin files to `/wp-content/plugins/priority-ticket-payment/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings under **Priority Tickets > Settings**
4. Set up your WooCommerce products for each priority tier
5. Configure Awesome Support priority terms (a-ticket, b-ticket, c-ticket)

## ⚙️ **Configuration**

### **Priority Tiers Setup**

The plugin uses three priority tiers that map to Awesome Support priorities:

| Tier | Description | Price | Awesome Support Priority |
|------|-------------|-------|-------------------------|
| **A** | Premium | €100 | a-ticket (ID: 134) |
| **B** | Standard | €50 | b-ticket (ID: 135) |
| **C** | Free | €0 | c-ticket (ID: 136) |

### **Elementor Forms Configuration**

1. Create separate Elementor forms for each priority tier
2. Configure form field mappings in plugin settings
3. Set form IDs in: **Priority Tickets > Settings > Form Mapping**

### **WooCommerce Products**

The plugin automatically creates WooCommerce products for paid tiers:
- Products are marked with `_priority_ticket_product = 'yes'`
- Hidden from catalog but accessible via direct purchase
- Auto-completion enabled for priority ticket orders

## 🎯 **Usage**

### **Form Submission Process**

1. **User submits Elementor form** with support request
2. **Plugin determines priority tier** based on user permissions/selection
3. **For paid tiers**: Redirects to WooCommerce checkout
4. **For free tier**: Creates ticket immediately
5. **After payment**: Auto-creates Awesome Support ticket with proper priority

### **Admin Management**

- **View Submissions**: `WP Admin > Priority Tickets > Submissions`
- **Create Tickets**: Click "Create Ticket" for completed submissions
- **Settings**: `WP Admin > Priority Tickets > Settings`
- **Cleanup**: `WP Admin > Priority Tickets > Cleanup`

## 🔧 **Hooks & Filters**

### **Actions**
```php
// Triggered when order is completed
do_action('priority_ticket_payment_order_completed', $order_id, $submission_id, $submission);

// Triggered when product is created
do_action('priority_ticket_payment_product_created', $product_id);

// Triggered after daily cleanup
do_action('priority_ticket_payment_daily_cleanup_completed', $deleted_submissions, $deleted_files);
```

### **Filters**
```php
// Customize ticket data before creation
apply_filters('priority_ticket_payment_ticket_data', $ticket_data, $submission, $order);

// Customize priority mapping
apply_filters('priority_ticket_payment_priority_map', $priority_map);
```

## 📊 **Database Structure**

### **Submissions Table** (`wp_priority_ticket_submissions`)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint | WordPress user ID |
| `form_data` | longtext | Serialized form submission data |
| `attachments` | longtext | Serialized attachment data |
| `price` | decimal(10,2) | Ticket price |
| `payment_status` | varchar(20) | Payment status |
| `order_id` | bigint | WooCommerce order ID |
| `token` | varchar(255) | Unique submission token |
| `awesome_support_ticket_id` | bigint | Created ticket ID |
| `created_at` | datetime | Submission timestamp |

## 🚨 **Troubleshooting**

### **Common Issues**

**1. Tickets not created automatically**
- Check if Awesome Support is active
- Verify `wpas_insert_ticket` function exists
- Check error logs for detailed messages

**2. Priority not set correctly**
- Verify priority term IDs (134, 135, 136)
- Check `ticket_priority` taxonomy in Awesome Support

**3. Form submissions not processed**
- Verify Elementor Pro is active
- Check form ID configuration
- Ensure `elementor_pro/forms/new_record` hook is firing

### **Debug Logging**

All plugin operations are logged to WordPress error logs with prefix `Priority Ticket Payment:`. Enable debug logging in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 🛡 **Security Features**

- **Nonce Protection**: All AJAX requests protected with WordPress nonces
- **Capability Checks**: Admin functions require appropriate permissions
- **File Upload Security**: Restricted file types and size limits
- **Sanitization**: All user inputs properly sanitized and validated

## 🔄 **Changelog**

### **Version 1.1.0** (2025-05-29)
- ✅ **Fixed**: Awesome Support integration using correct `wpas_insert_ticket` function
- ✅ **Fixed**: Priority taxonomy assignment with proper term IDs
- ✅ **Fixed**: Form data deserialization and placeholder filtering
- ✅ **Fixed**: User priority detection with multiple fallback methods
- ✅ **Fixed**: Submission status updates on order completion
- ✅ **Enhanced**: Coach assignment with placeholder value filtering
- ✅ **Enhanced**: Comprehensive error handling and logging
- ✅ **Enhanced**: Priority display with proper tier mapping

### **Version 1.0.0** (2025-05-28)
- 🎉 **Initial Release**
- ⭐ Basic Elementor Pro forms integration
- ⭐ WooCommerce payment processing
- ⭐ Awesome Support ticket creation
- ⭐ Admin interface for submission management
- ⭐ Multi-tier priority system

## 👨‍💻 **Support**

For support, feature requests, or bug reports:

- **Website**: [https://sparkwebstudio.com/](https://sparkwebstudio.com/)
- **Documentation**: Check the plugin's built-in help sections
- **WordPress Repository**: Submit issues through the WordPress plugin repository

## 📄 **License**

This plugin is licensed under the GPL v2 or later.

---

**Developed by [SPARKWEBStudio](https://sparkwebstudio.com/) - Premium WordPress Development** 