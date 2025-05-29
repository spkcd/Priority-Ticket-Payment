# Awesome Support Integration for Priority Ticket Payment

This document describes the Awesome Support integration functionality for creating tickets upon payment completion.

## Overview

The Priority Ticket Payment plugin integrates with Awesome Support to automatically create support tickets when WooCommerce orders containing priority ticket products are completed. This ensures a seamless flow from payment to ticket creation with proper priority assignment and file attachments.

## Payment Completion Workflow

### Order Completion Hook

When a WooCommerce order is marked as "completed", the system automatically:

1. **Detects Priority Ticket Orders**: Checks if the order contains products marked as priority ticket products
2. **Extracts Order Metadata**: Retrieves ticket token, submission ID, and user priority tier from order meta
3. **Loads Submission Data**: Fetches the original form submission from the database
4. **Prevents Duplicate Processing**: Checks if a ticket has already been created for this submission
5. **Creates Awesome Support Ticket**: Uses form data and order information to create the ticket
6. **Updates Database**: Marks submission as "paid" and links to WooCommerce order
7. **Sends Notifications**: Emails customer and admin about successful ticket creation

## Priority Tier Mapping

The system maps user priority tiers to Awesome Support priority levels:

| User Priority | Description | Awesome Support Priority | Attachments |
|---------------|-------------|-------------------------|-------------|
| **A** | Premium (100€) | 3 (Urgent) | ✅ Supported |
| **B** | Standard (50€) | 2 (High) | ✅ Supported |
| **C** | Free | 1 (Medium) | ❌ Skipped |

## Ticket Creation Process

### 1. Ticket Data Preparation

The system builds comprehensive ticket data from:

- **Form submission data** (subject, description, contact info)
- **Order information** (order ID, total, payment method, customer details)
- **Priority tier** (determines urgency level and agent assignment)

### 2. Ticket Metadata Assignment

Each created ticket receives extensive metadata:

- `_wpas_priority`: Awesome Support priority level (1-3)
- `_priority_ticket_order_id`: WooCommerce order ID
- `_priority_ticket_submission_id`: Original submission ID
- `_priority_ticket_token`: Unique submission token
- `_priority_ticket_tier`: User priority tier (A/B/C)
- `_priority_ticket_price`: Paid amount
- `_priority_ticket_form_data`: Complete form submission data
- `_priority_ticket_elementor_form_id`: Source Elementor form ID

### 3. Agent Assignment

For paid tiers (A & B), the system attempts automatic agent assignment:

1. **Coach Preference**: If user specified a preferred coach in the form
2. **Name Matching**: Searches for agents by display name or username
3. **Permission Verification**: Ensures the user has `edit_ticket` capability
4. **Assignment**: Links the agent to the ticket using `_wpas_assignee` meta

### 4. File Attachments

For paid tiers only, the system processes file attachments:

- **File Location**: Downloads from secure `wp-content/uploads/priority-tickets/` directory
- **WordPress Integration**: Copies files to standard WordPress uploads directory
- **Attachment Creation**: Creates WordPress attachment posts linked to the ticket
- **Metadata Preservation**: Maintains original filename and field name references
- **Security**: Validates file types and sizes before processing

### 5. Order Documentation

A comprehensive order note is added to each ticket containing:

- Order ID and creation date
- Order total and payment method
- Customer billing information
- Direct link to WooCommerce order admin page

## Email Notifications

### Customer Notification

Automatically sends confirmation email to customer containing:

- Ticket ID and subject
- Priority level assigned
- Order reference information
- Next steps information
- Support contact details

### Admin Notification

Sends detailed notification to site administrator with:

- Complete order and ticket details
- Customer information
- Quick action links to view ticket and order
- Priority level and tier information

## Error Handling

The system includes comprehensive error handling for:

### Missing Dependencies
- Verifies Awesome Support is active before processing
- Gracefully handles missing WooCommerce integration
- Logs errors when required functions are unavailable

### Data Validation
- Validates ticket data before creation
- Ensures required fields are present
- Handles missing or corrupted submission data

### File Processing
- Continues processing even if individual files fail
- Logs detailed error messages for debugging
- Maintains submission integrity despite file issues

### Duplicate Prevention
- Checks for existing tickets to prevent duplicates
- Uses database flags to track processing status
- Handles race conditions during order completion

## Database Updates

Upon successful ticket creation, the system updates:

### Submission Record
```sql
UPDATE wp_priority_ticket_submissions SET
  payment_status = 'paid',
  order_id = [order_id],
  awesome_support_ticket_id = [ticket_id]
WHERE id = [submission_id]
```

### Tracking Fields
- Links submission to WooCommerce order
- Stores Awesome Support ticket ID for reference
- Updates payment status for reporting

## Configuration Options

The integration respects several plugin settings:

- `enable_awesome_support_integration`: Master toggle for integration
- Form Mapping settings for priority tier determination
- File upload settings for attachment processing
- Email notification preferences

## Testing the Integration

### Manual Testing Steps

1. **Setup**: Configure Form Mapping settings and ensure Awesome Support is active
2. **Submit Form**: Use a test Elementor form with different user priority tiers
3. **Complete Payment**: Process the WooCommerce order to completion
4. **Verify Creation**: Check that Awesome Support ticket was created
5. **Check Metadata**: Verify priority level and agent assignment
6. **Test Attachments**: Confirm files are properly attached (paid tiers only)
7. **Email Verification**: Check that notification emails were sent

### Debug Information

Enable WordPress debug logging to see detailed processing information:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Look for log entries prefixed with "Priority Ticket Payment:" in `/wp-content/debug.log`.

## Integration Points

### With Elementor Integration
- Receives form submission data and metadata
- Uses ticket token for order matching
- Inherits file attachment processing

### With WooCommerce
- Hooks into order completion events
- Extracts order metadata and customer information
- Links tickets to order records

### With Database Layer
- Updates submission status and references
- Maintains data integrity across components
- Provides reporting and metrics data

## Troubleshooting

### Common Issues

1. **No Ticket Created**
   - Verify Awesome Support is active and `wpas_create_ticket` function exists
   - Check order contains priority ticket products
   - Ensure order has required metadata (token, submission ID)

2. **Missing Attachments**
   - Verify user is on paid tier (A or B)
   - Check file paths exist in submission data
   - Ensure upload directory permissions are correct

3. **Agent Assignment Failed**
   - Verify agent name matches WordPress user display name
   - Check user has `edit_ticket` capability
   - Ensure user is configured as Awesome Support agent

4. **Email Issues**
   - Check WordPress mail configuration
   - Verify customer email addresses are valid
   - Test with SMTP plugin if needed

### Debug Functions

The class includes utility functions for debugging:

- `Priority_Ticket_Payment_Awesome_Support_Utils::create_test_ticket()`: Creates test ticket
- `Priority_Ticket_Payment_Awesome_Support_Utils::validate_ticket_data()`: Validates ticket data
- `Priority_Ticket_Payment_Awesome_Support_Utils::get_priority_ticket_metrics()`: Gets statistics

## Security Considerations

### File Handling
- Validates file types and sizes before processing
- Uses secure temporary storage with access restrictions
- Sanitizes filenames to prevent path traversal attacks

### Data Validation
- Sanitizes all form input before ticket creation
- Validates user permissions for agent assignment
- Prevents SQL injection through prepared statements

### Access Control
- Respects Awesome Support permission system
- Maintains WordPress user role restrictions
- Logs all processing activities for audit trails

## Performance Optimization

### Efficient Processing
- Processes attachments in batches
- Uses database transactions for data integrity
- Caches frequently accessed data

### Resource Management
- Cleans up temporary files after processing
- Limits file processing to prevent timeouts
- Uses WordPress hooks for non-blocking execution

## Future Enhancements

Potential improvements to the integration:

1. **Advanced Agent Assignment**: Rule-based agent assignment by topic or workload
2. **Custom Fields**: Support for additional Awesome Support custom fields
3. **Ticket Templates**: Customizable ticket content templates
4. **Status Synchronization**: Bi-directional status updates between systems
5. **Reporting Integration**: Enhanced metrics and reporting features

## API Reference

### Main Handler
```php
Priority_Ticket_Payment_Awesome_Support_Utils::handle_order_completion($order_id)
```

### Ticket Creation
```php
Priority_Ticket_Payment_Awesome_Support_Utils::create_ticket_from_submission($submission, $order, $user_priority)
```

### Utility Functions
```php
Priority_Ticket_Payment_Awesome_Support_Utils::is_awesome_support_active()
Priority_Ticket_Payment_Awesome_Support_Utils::get_available_agents()
Priority_Ticket_Payment_Awesome_Support_Utils::get_priority_levels()
``` 