# Changelog

All notable changes to the Priority Ticket Payment plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-05-29

### 🔧 **Fixed**
- **Critical Fix**: Updated Awesome Support integration to use correct `wpas_insert_ticket` function instead of non-existent `wpas_create_ticket`
- **Priority Assignment**: Fixed priority taxonomy assignment using proper Awesome Support term IDs (134, 135, 136)
- **Form Data Handling**: Fixed PHP fatal error when `form_data` parameter was not properly deserialized
- **WP_Error Handling**: Added comprehensive error handling for `get_terms()` calls that could return WP_Error objects
- **Order Completion**: Fixed submission status not updating from "pending_payment" to "completed" on order completion
- **User Priority Detection**: Implemented multi-level fallback system for detecting user priority tier

### ✨ **Enhanced**
- **Priority Display**: Updated ticket content to show proper priority names ("Premium (a-ticket)", "Standard (b-ticket)", "Free (c-ticket)")
- **Coach Assignment**: Added filtering of placeholder values ("– Wer ist Ihr Coach? –", "Select a coach", etc.) from coach assignments
- **Error Logging**: Enhanced debug logging throughout the system for better troubleshooting
- **Data Validation**: Improved form data validation and array safety checks
- **Metadata Handling**: Enhanced ticket metadata assignment with both meta fields and taxonomy terms

### 🏗️ **Technical Improvements**
- **Function Detection**: Improved Awesome Support plugin detection with multiple fallback checks
- **Priority Mapping**: Implemented proper mapping of plugin tiers (A/B/C) to Awesome Support priority terms
- **Order Processing**: Enhanced order completion handler with better metadata retrieval and processing
- **Form Deserialization**: Added robust form data deserialization with error handling
- **Placeholder Filtering**: Comprehensive filtering system for removing placeholder values from form fields

---

## [1.0.0] - 2025-05-28

### 🎉 **Initial Release**

#### ⭐ **Core Features**
- **Multi-tier Priority System**: Implemented A (Premium), B (Standard), C (Free) priority tiers
- **Database Infrastructure**: Created custom `wp_priority_ticket_submissions` table with comprehensive field structure
- **Admin Interface**: Complete WordPress admin interface for managing submissions and settings
- **Frontend Integration**: User-facing submission forms and status tracking

#### 🛒 **WooCommerce Integration**
- **Payment Processing**: Seamless integration with WooCommerce for paid support tiers
- **Product Management**: Automatic creation and management of priority ticket products
- **Order Tracking**: Full lifecycle tracking from submission to payment completion
- **Auto-completion**: Automatic order completion for priority ticket products

#### 🎫 **Ticket Management**
- **Awesome Support Ready**: Prepared integration hooks for Awesome Support plugin
- **Metadata System**: Rich metadata storage for enhanced ticket tracking
- **File Attachments**: Support for file uploads with security restrictions
- **Status Tracking**: Comprehensive payment and ticket status management

#### 🎨 **Elementor Pro Integration**
- **Form Handling**: Direct integration with Elementor Pro form submissions
- **Field Mapping**: Intelligent mapping of form fields to ticket data
- **Multi-form Support**: Support for separate forms for each priority tier
- **Validation**: Comprehensive form data validation and sanitization

#### ⚡ **Performance & Security**
- **Efficient Queries**: Optimized database queries with proper indexing
- **Security Measures**: Nonce protection, capability checks, and input sanitization
- **File Security**: Secure file upload handling with type and size restrictions
- **Cleanup System**: Automated cleanup of old pending submissions

#### 🔧 **Administration**
- **Settings Management**: Comprehensive settings panel with form mapping
- **Submission Overview**: Detailed view of all submissions with filtering
- **Manual Operations**: Manual ticket creation for completed submissions
- **Cleanup Tools**: Tools for managing and cleaning up old data

#### 📧 **Communication**
- **Email Notifications**: Automatic notifications for users and administrators
- **Status Updates**: Real-time status updates throughout the process
- **Error Reporting**: Comprehensive error logging and reporting system

#### 🏗️ **Developer Features**
- **Hook System**: Extensive action and filter hooks for customization
- **API Functions**: Clean API for developers to extend functionality
- **Documentation**: Comprehensive inline documentation and code comments
- **Extensibility**: Modular architecture designed for easy extension

#### 🌐 **Internationalization**
- **Translation Ready**: Full support for WordPress translation system
- **Text Domain**: Proper text domain implementation for all user-facing strings
- **RTL Support**: Ready for right-to-left language support

---

## Upgrade Notes

### From 1.0.0 to 1.1.0

**Important Changes:**
- The plugin now requires Awesome Support to be active for full functionality
- Priority term IDs must be properly configured (134, 135, 136)
- Enhanced error logging may reveal previously hidden integration issues

**Manual Steps Required:**
1. Verify Awesome Support plugin is active and updated
2. Check that priority terms exist with correct IDs:
   - a-ticket: ID 134
   - b-ticket: ID 135  
   - c-ticket: ID 136
3. Test form submissions to ensure proper ticket creation
4. Review error logs for any integration issues

**Database Changes:**
- No database schema changes required
- Existing submissions will continue to work normally

---

## Support Information

For technical support, bug reports, or feature requests:

- **Developer**: SPARKWEBStudio
- **Website**: [https://sparkwebstudio.com/](https://sparkwebstudio.com/)
- **Plugin Version**: Check the main plugin file header for current version
- **WordPress Compatibility**: 5.0+ (tested up to 6.4)
- **PHP Compatibility**: 7.4+ (recommended: 8.0+)

---

**Note**: This changelog follows semantic versioning where:
- **MAJOR** version (X.0.0) - Incompatible API changes
- **MINOR** version (0.X.0) - New functionality in a backwards compatible manner  
- **PATCH** version (0.0.X) - Backwards compatible bug fixes 