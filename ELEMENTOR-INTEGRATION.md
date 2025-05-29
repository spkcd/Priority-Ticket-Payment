# Elementor Integration for Priority Ticket Payment

This document describes the Elementor Pro Forms integration for the Priority Ticket Payment plugin.

## Overview

The plugin integrates with Elementor Pro Forms to provide a seamless priority ticket submission experience with automatic pricing based on user purchase history and tier assignment.

## Priority-Based Ticket System

### User Priority Tiers

The system automatically determines user priority based on purchase history:

- **Priority A (Premium - 100€)**: Users with coaching product purchases
- **Priority B (Standard - 50€)**: Users with completed/processing orders
- **Priority C (Free)**: Guest users or users without qualifying purchases

### Form Configuration

Configure forms for each priority tier in **WP Admin > Priority Tickets > Settings > Form Mapping**:

1. **Form ID for Ticket A (100€)** - `ticket_form_id_a`
2. **Form ID for Ticket B (50€)** - `ticket_form_id_b` 
3. **Form ID for Ticket C (Free)** - `ticket_form_id_c`
4. **WooCommerce Product ID for Ticket A** - `product_id_a`
5. **WooCommerce Product ID for Ticket B** - `product_id_b`
6. **Coaching Product ID(s)** - `coaching_product_ids` (comma-separated)

## Workflow

### 1. Form Submission (`elementor_pro/forms/new_record`)

When a user submits an Elementor form:

1. **Form Validation**: Checks if form ID matches any configured priority form
2. **User Priority Detection**: Uses `Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority()`
3. **Pricing Configuration**: Maps priority to price and product using Form Mapping settings
4. **Database Storage**: Saves submission with UUID token
5. **Routing Decision**:
   - **Free Tier (C)**: Creates support ticket immediately and redirects to thank you page
   - **Paid Tiers (A/B)**: Redirects to WooCommerce checkout

### 2. Checkout Process (`woocommerce_checkout_create_order`)

For paid tiers:

1. **Product Addition**: Adds configured product to cart with ticket metadata
2. **Session Storage**: Stores ticket token and submission ID in WooCommerce session
3. **Order Metadata**: Attaches ticket information to order using multiple fallback methods:
   - URL parameters (`ticket_id`, `submission_id`)
   - WooCommerce session data
   - Cart item metadata

### 3. Payment Completion (`woocommerce_order_status_completed`)

After successful payment:

1. **Ticket Creation**: Creates Awesome Support ticket with priority-based metadata
2. **File Attachments**: Downloads and attaches files from temporary storage
3. **Metadata Assignment**: Sets priority, agent assignment, and custom fields
4. **Notifications**: Sends completion emails and adds order notes

## Free Tier Handling

For Priority C (Free) users:

- **Character Limit**: Description limited to 500 characters with live counter
- **No Uploads**: File upload fields are hidden via JavaScript
- **Immediate Processing**: Support ticket created without payment
- **Low Priority**: Assigned priority 0 in Awesome Support

## Field Mapping

The integration supports flexible field mapping with multiple naming variations:

```php
$variations = array(
    'name' => array('name', 'full_name', 'full name', 'client_name', 'your_name'),
    'email' => array('email', 'email_address', 'e-mail', 'your_email'),
    'phone' => array('phone', 'telephone', 'phone_number', 'contact_phone'),
    'urgency' => array('urgency', 'priority', 'urgent', 'priority_level'),
    'date_note' => array('date_note', 'date note', 'preferred_date', 'date'),
    'coach' => array('coach', 'trainer', 'preferred_coach', 'coach_preference'),
    'message' => array('message', 'description', 'details', 'comments', 'note'),
    'website' => array('website', 'url', 'site', 'website_url'),
);
```

## File Upload Security

For paid tiers (A and B):

- **Secure Storage**: Files saved to `wp-content/uploads/priority-tickets/`
- **Filename Sanitization**: Alphanumeric characters, underscores, and dashes only
- **File Type Validation**: Restricted to allowed extensions
- **Size Limits**: Configurable maximum file sizes
- **Unique Naming**: Prevents filename conflicts
- **Access Protection**: `.htaccess` file prevents direct access

## Frontend Form Display

The `[priority_ticket_form]` shortcode automatically:

1. **Detects User Priority**: Uses utility function to determine tier
2. **Loads Appropriate Form**: Renders Elementor form based on priority
3. **Applies Restrictions**: For free tier, limits description and hides uploads
4. **Error Handling**: Shows user-friendly messages for configuration issues

## Session and Metadata Management

### Session Data
- `priority_ticket_token`: UUID for submission identification
- `priority_ticket_submission_id`: Database record ID

### Order Metadata
- `_priority_ticket_token`: UUID token
- `_priority_ticket_submission_id`: Submission record ID
- `_priority_ticket_tier`: User priority tier (premium/standard/free)
- `_priority_ticket_*`: All form field data
- `_priority_ticket_attachments`: File attachment information

## Error Handling

The integration includes comprehensive error handling:

- **Database Failures**: Logged with submission details
- **File Upload Issues**: Individual file errors don't block submission
- **WooCommerce Errors**: Fallback to manual product creation
- **Elementor Issues**: Graceful degradation with error messages
- **Session Problems**: Multiple fallback methods for data retrieval

## Integration Testing

To test the integration:

1. **Configure Forms**: Set up Form Mapping settings with valid Elementor form IDs
2. **Create Test Users**: Users with different purchase histories for each tier
3. **Submit Forms**: Test each priority tier form submission
4. **Verify Checkout**: Ensure proper product addition and metadata
5. **Check Completion**: Confirm ticket creation and file attachments

## Troubleshooting

### Common Issues

1. **Form Not Loading**: Check Form Mapping configuration and Elementor Pro availability
2. **Wrong Pricing**: Verify user priority detection and Form Mapping settings
3. **Missing Metadata**: Check session handling and checkout hooks
4. **File Upload Errors**: Verify upload directory permissions and file restrictions

### Debug Logging

Enable WordPress debug logging to see detailed error messages:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `/wp-content/debug.log` for Priority Ticket Payment messages.

## Prerequisites

- WordPress with Priority Ticket Payment plugin activated
- Elementor Pro plugin installed and activated
- WooCommerce plugin installed and activated (for payment processing)

## Setup Instructions

### 1. Enable Integration

1. Go to **WordPress Admin > Priority Tickets > Settings**
2. Navigate to the **Elementor Pro Integration** section
3. Check **"Enable Elementor Pro Integration"**
4. Save settings

### 2. Configure Form ID

1. Create or edit your Elementor Pro form
2. In the form settings, go to **Form Options > Form ID**
3. Set a unique ID (e.g., `priority-support-form`)
4. Copy this ID to the plugin settings under **"Elementor Form ID"**

### 3. Configure Products (Optional)

The plugin can automatically create WooCommerce products, or you can specify existing ones:

- **Auto-creation**: Leave product ID fields empty
- **Manual**: Enter existing WooCommerce product IDs for $50 and $100 tiers

## Form Field Requirements

Your Elementor form must include these **required fields**:

| Field Type | Field Label/Name | Purpose |
|------------|------------------|---------|
| Text | Name | Customer name |
| Email | Email | Contact email |

### Optional Fields

| Field Type | Field Label/Name | Purpose | Notes |
|------------|------------------|---------|--------|
| Phone | Phone | Contact phone | |
| Select/Text | Urgency/Priority | Priority level | Affects pricing |
| Text | Coach | Preferred coach | Enables premium pricing |
| Textarea | Message | Support request details | |
| Text | Website | Customer website | |
| Date | Date Note | Preferred date/time | |
| Upload | Attachments | File uploads | |

## Field Mapping

The integration automatically maps fields based on field names and labels. It supports various naming conventions:

### Name Field Variations
- `name`, `full_name`, `full name`, `client_name`, `your_name`

### Email Field Variations  
- `email`, `email_address`, `e-mail`, `your_email`

### Phone Field Variations
- `phone`, `telephone`, `phone_number`, `contact_phone`

### Priority/Urgency Variations
- `urgency`, `priority`, `urgent`, `priority_level`

### Other Field Variations
- **Coach**: `coach`, `trainer`, `preferred_coach`, `coach_preference`
- **Message**: `message`, `description`, `details`, `comments`, `note`
- **Website**: `website`, `url`, `site`, `website_url`
- **Date**: `date_note`, `date note`, `preferred_date`, `date`

## Pricing Logic

The integration determines pricing based on these criteria:

### $100 Premium Pricing
- User has `has_coaching_booking` meta field set to true
- Urgency field contains: `urgent`, `high`, `emergency`
- Coach field is filled out

### $50 Standard Pricing
- Default for all other cases

## Workflow

1. **Form Submission**: User submits Elementor form
2. **Validation**: Plugin validates required fields
3. **Data Processing**: Form data is captured and processed
4. **Price Calculation**: Price determined based on logic above
5. **Database Storage**: Submission saved with `pending_payment` status
6. **Token Generation**: Unique UUID token created
7. **Cart Setup**: WooCommerce product added to cart
8. **Redirect**: User redirected to checkout with token parameter
9. **Order Completion**: When payment is completed, triggers Awesome Support ticket creation

## Awesome Support Integration

When a WooCommerce order containing a priority ticket product is completed, the plugin automatically:

### 1. **Ticket Creation**
- Creates new Awesome Support ticket using `wpas_create_ticket()`
- Populates title and content from form submission
- Sets appropriate priority level based on urgency field
- Assigns to preferred coach if specified

### 2. **File Attachments**
- Downloads files from Elementor uploads
- Attaches files to the support ticket
- Handles both URL-based and local file attachments

### 3. **Metadata Assignment**
- Sets ticket priority (Urgent=3, High=2, Medium=1, Low=0)
- Assigns agent if coach preference specified
- Links to WooCommerce order
- Stores original form data as custom fields

### 4. **Order Information**
- Adds order details as ticket note
- Includes order ID, total, payment method, customer info
- Links to WooCommerce order edit page

### 5. **Email Notifications**
- Sends confirmation email to customer
- Notifies admin of new priority ticket creation
- Includes ticket and order reference information

### 6. **Status Synchronization**
- Updates submission status to `completed`
- Maps payment status to ticket status
- Maintains link between order, submission, and ticket

## URL Parameters

The checkout URL includes these parameters:
- `ticket_id`: UUID token for tracking
- `submission_id`: Database submission ID

Example: `https://example.com/checkout/?ticket_id=abc123-def456&submission_id=789`

## Database Storage

Form submissions are stored in `wp_priority_ticket_submissions` with:

```sql
id, user_id, form_data, attachments, price, 
created_at, payment_status, order_id, token, awesome_support_ticket_id
```

### Form Data Structure
```php
[
    'ticket_subject' => 'John Doe - Priority Support Request',
    'ticket_priority' => 'high',
    'ticket_category' => 'general', 
    'ticket_description' => 'Message: Need help with...',
    'contact_email' => 'john@example.com',
    'contact_phone' => '+1-555-123-4567',
    'elementor_form_id' => 'priority-support-form',
    'date_note' => '2024-01-15',
    'coach' => 'Sarah Johnson',
    'website' => 'https://johndoe.com',
    'original_fields' => [...] // Raw Elementor field data
]
```

## Payment Processing

1. **Product Creation**: Automatic WooCommerce product creation
2. **Cart Management**: Product added with submission metadata
3. **Checkout**: Standard WooCommerce checkout process
4. **Completion**: Order completion updates submission status

## Status Updates

| Status | Description |
|--------|-------------|
| `pending_payment` | Initial submission, awaiting payment |
| `processing` | Payment received, order processing |
| `completed` | Payment confirmed |
| `failed` | Payment failed |
| `refunded` | Payment refunded |

## Hooks and Actions

### Available Actions
```php
// After successful order completion
do_action('priority_ticket_payment_elementor_order_completed', $order_id, $submission_id, $token);
```

### Integration Hooks
```php
// Modify pricing logic
add_filter('priority_ticket_payment_elementor_price', function($price, $fields) {
    // Custom pricing logic
    return $price;
}, 10, 2);

// Modify form data before saving
add_filter('priority_ticket_payment_elementor_form_data', function($form_data, $fields) {
    // Custom data processing
    return $form_data;
}, 10, 2);
```

## Troubleshooting

### Form Not Triggering
1. Verify Elementor Pro is active
2. Check form ID matches settings
3. Enable WordPress debug logging
4. Check error logs for messages

### Field Mapping Issues
1. Ensure required fields (name, email) are present
2. Check field names match supported variations
3. Use debug mode to log field data

### Payment Issues
1. Verify WooCommerce is active and configured
2. Check product creation/assignment
3. Test checkout process manually

### Debug Mode
Enable debugging by adding to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Debug messages will appear in `/wp-content/debug.log`

## Advanced Configuration

### Custom User Meta Check
```php
// Custom logic for has_coaching_booking
add_filter('priority_ticket_payment_elementor_has_coaching', function($has_coaching, $user_id) {
    // Custom logic to determine coaching status
    return get_user_meta($user_id, 'custom_coaching_field', true);
}, 10, 2);
```

### Custom Field Processing
```php
// Process additional custom fields
add_action('priority_ticket_payment_elementor_process_fields', function($fields, $submission_id) {
    // Handle custom field processing
}, 10, 2);
```

## Example Form Configuration

Create an Elementor form with these fields:

1. **Text Field**: Label "Full Name", Name "name"
2. **Email Field**: Label "Email Address", Name "email"  
3. **Phone Field**: Label "Phone Number", Name "phone"
4. **Select Field**: Label "Urgency Level", Name "urgency"
   - Options: Low, Medium, High, Urgent
5. **Text Field**: Label "Preferred Coach", Name "coach"
6. **Textarea**: Label "Message", Name "message"
7. **Upload Field**: Label "Attachments", Name "attachments"

Set Form ID to `priority-support-form` and configure the plugin accordingly.

## Testing

1. Create test form with required fields
2. Submit test data
3. Check database for submission record
4. Verify redirect to checkout
5. Complete test payment
6. Confirm status updates

## Support

For issues with this integration:
1. Check WordPress debug logs
2. Verify all requirements are met
3. Test with minimal form configuration
4. Review field mapping requirements 

## File Upload Handling

### Upload Restrictions
- **Maximum Files**: 3 files per submission
- **File Size Limit**: Configurable via plugin settings (default: 10MB per file)
- **Allowed File Types**: Configurable via plugin settings (default: pdf, doc, docx, jpg, jpeg, png, gif)

### File Storage
Files uploaded through Elementor forms are processed as follows:

1. **Download**: Files are downloaded from Elementor's temporary URLs
2. **Sanitization**: Filenames are sanitized for security
3. **Storage**: Files are saved to `wp-content/uploads/priority-tickets/` directory
4. **Database**: Full file paths and metadata are stored in the database

### File Security
- Upload directory is protected with `.htaccess` (Options -Indexes, deny from all)
- Index.php file prevents directory browsing
- Filenames are sanitized to prevent path traversal attacks
- File types are validated against allowed extensions
- File size limits are enforced

### File Data Structure
```php
[
    'original_name' => 'document.pdf',           // Original filename
    'filename' => 'document_1.pdf',             // Sanitized unique filename
    'path' => '/path/to/wp-content/uploads/priority-tickets/document_1.pdf',
    'size' => 1048576,                          // File size in bytes
    'type' => 'pdf',                            // File extension
    'field_name' => 'Attachments',              // Form field name
    'mime_type' => 'application/pdf',           // MIME type
    'upload_date' => '2024-01-15 10:30:00'      // Upload timestamp
]
``` 