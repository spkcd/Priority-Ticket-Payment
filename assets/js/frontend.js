/**
 * Frontend JavaScript for Priority Ticket Payment
 */

(function($) {
    'use strict';

    // Frontend functionality
    var PriorityTicketFrontend = {
        
        /**
         * Initialize frontend functionality
         */
        init: function() {
            this.bindEvents();
            this.initFormValidation();
            this.initFileUpload();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Form submission
            $(document).on('submit', '#priority-ticket-form', this.handleFormSubmission);
            
            // File input change
            $(document).on('change', '#ticket_attachments', this.handleFileSelection);
            
            // Priority change (update price if needed)
            $(document).on('change', '#ticket_priority', this.handlePriorityChange);
            
            // Real-time form validation
            $(document).on('blur', '.form-control', this.validateField);
            $(document).on('input', '.form-control', this.clearFieldError);
            
            // Character counter for textarea
            $(document).on('input', '#ticket_description', this.updateCharacterCount);
        },
        
        /**
         * Initialize form validation
         */
        initFormValidation: function() {
            // Add required indicators
            $('.form-control[required]').each(function() {
                var $label = $('label[for="' + $(this).attr('id') + '"]');
                if ($label.length && !$label.hasClass('required')) {
                    $label.addClass('required');
                }
            });
            
            // Add character counter to description
            var $description = $('#ticket_description');
            if ($description.length) {
                var maxLength = $description.attr('maxlength') || 1000;
                $description.after('<div class="character-count">0 / ' + maxLength + ' characters</div>');
            }
        },
        
        /**
         * Initialize file upload functionality
         */
        initFileUpload: function() {
            var $fileInput = $('#ticket_attachments');
            if ($fileInput.length) {
                // Add drag and drop functionality
                var $container = $fileInput.closest('.form-group');
                
                $container.on('dragover dragenter', function(e) {
                    e.preventDefault();
                    $(this).addClass('drag-over');
                });
                
                $container.on('dragleave', function(e) {
                    e.preventDefault();
                    $(this).removeClass('drag-over');
                });
                
                $container.on('drop', function(e) {
                    e.preventDefault();
                    $(this).removeClass('drag-over');
                    
                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length > 0) {
                        $fileInput[0].files = files;
                        PriorityTicketFrontend.handleFileSelection.call($fileInput[0]);
                    }
                });
            }
        },
        
        /**
         * Handle form submission
         */
        handleFormSubmission: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var $messages = $('#form-messages');
            
            // Clear previous messages
            $messages.empty();
            
            // Validate form
            if (!PriorityTicketFrontend.validateForm($form)) {
                return false;
            }
            
            // Disable submit button and show loading
            $submitBtn.prop('disabled', true)
                     .html('<span class="loading-spinner"></span> Processing...');
            
            // Prepare form data
            var formData = new FormData($form[0]);
            
            // Submit via AJAX
            $.ajax({
                url: priority_ticket_payment_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        PriorityTicketFrontend.showMessage(response.data.message, 'success');
                        
                        if (response.data.redirect && response.data.payment_url) {
                            // Redirect to payment
                            setTimeout(function() {
                                window.location.href = response.data.payment_url;
                            }, 2000);
                        } else {
                            // Reset form
                            $form[0].reset();
                            PriorityTicketFrontend.updateCharacterCount.call($('#ticket_description')[0]);
                        }
                    } else {
                        PriorityTicketFrontend.showMessage(response.data, 'error');
                    }
                },
                error: function(xhr) {
                    var message = 'An error occurred while submitting your ticket. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        message = xhr.responseJSON.data;
                    }
                    PriorityTicketFrontend.showMessage(message, 'error');
                },
                complete: function() {
                    // Re-enable submit button
                    $submitBtn.prop('disabled', false)
                             .html($submitBtn.data('original-text') || 'Submit & Pay');
                }
            });
        },
        
        /**
         * Handle file selection
         */
        handleFileSelection: function() {
            var $input = $(this);
            var files = this.files;
            var $preview = $input.siblings('.file-preview');
            
            // Create preview container if it doesn't exist
            if ($preview.length === 0) {
                $preview = $('<div class="file-preview"></div>');
                $input.after($preview);
            }
            
            $preview.empty();
            
            if (files.length > 0) {
                var previewHTML = '<div class="selected-files"><strong>Selected files:</strong><ul>';
                
                for (var i = 0; i < files.length; i++) {
                    var file = files[i];
                    var size = PriorityTicketFrontend.formatFileSize(file.size);
                    var icon = PriorityTicketFrontend.getFileIcon(file.name);
                    
                    previewHTML += '<li>';
                    previewHTML += '<span class="file-icon">' + icon + '</span>';
                    previewHTML += '<span class="file-name">' + file.name + '</span>';
                    previewHTML += '<span class="file-size">(' + size + ')</span>';
                    previewHTML += '</li>';
                }
                
                previewHTML += '</ul></div>';
                $preview.html(previewHTML);
            }
        },
        
        /**
         * Handle priority change
         */
        handlePriorityChange: function() {
            var priority = $(this).val();
            var $priceDisplay = $('.price-display');
            
            // This could be used to update pricing based on priority level
            // For now, it's a placeholder for future functionality
            
            // Example: Different pricing tiers
            var pricing = {
                'urgent': 100.00,
                'high': 75.00,
                'medium': 50.00,
                'low': 25.00
            };
            
            if (pricing[priority] && $priceDisplay.length) {
                var newPrice = pricing[priority];
                var currencySymbol = $priceDisplay.data('currency') || '$';
                
                $priceDisplay.find('strong').text('Price: ' + currencySymbol + newPrice.toFixed(2));
                $('input[name="ticket_price"]').val(newPrice);
            }
        },
        
        /**
         * Validate individual field
         */
        validateField: function() {
            var $field = $(this);
            var value = $field.val();
            var fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();
            var isValid = true;
            var errorMessage = '';
            
            // Remove existing error
            PriorityTicketFrontend.clearFieldError.call(this);
            
            // Required field validation
            if ($field.prop('required') && !value.trim()) {
                isValid = false;
                errorMessage = 'This field is required.';
            }
            
            // Email validation
            else if (fieldType === 'email' && value && !PriorityTicketFrontend.isValidEmail(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid email address.';
            }
            
            // Phone validation
            else if ($field.attr('name') === 'contact_phone' && value && !PriorityTicketFrontend.isValidPhone(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid phone number.';
            }
            
            // Show error if invalid
            if (!isValid) {
                PriorityTicketFrontend.showFieldError($field, errorMessage);
            }
            
            return isValid;
        },
        
        /**
         * Clear field error
         */
        clearFieldError: function() {
            var $field = $(this);
            $field.removeClass('error');
            $field.siblings('.field-error').remove();
        },
        
        /**
         * Show field error
         */
        showFieldError: function($field, message) {
            $field.addClass('error');
            
            var $error = $('<div class="field-error">' + message + '</div>');
            $field.after($error);
        },
        
        /**
         * Validate entire form
         */
        validateForm: function($form) {
            var isValid = true;
            
            // Validate all required fields
            $form.find('.form-control').each(function() {
                if (!PriorityTicketFrontend.validateField.call(this)) {
                    isValid = false;
                }
            });
            
            // File size validation
            var fileInput = $form.find('#ticket_attachments')[0];
            if (fileInput && fileInput.files.length > 0) {
                var maxFileSize = 10 * 1024 * 1024; // 10MB default
                
                for (var i = 0; i < fileInput.files.length; i++) {
                    if (fileInput.files[i].size > maxFileSize) {
                        PriorityTicketFrontend.showMessage('File "' + fileInput.files[i].name + '" is too large. Maximum size is 10MB.', 'error');
                        isValid = false;
                        break;
                    }
                }
            }
            
            return isValid;
        },
        
        /**
         * Update character count
         */
        updateCharacterCount: function() {
            var $textarea = $(this);
            var current = $textarea.val().length;
            var max = $textarea.attr('maxlength') || 1000;
            var $counter = $textarea.siblings('.character-count');
            
            if ($counter.length) {
                $counter.text(current + ' / ' + max + ' characters');
                
                if (current > max * 0.9) {
                    $counter.addClass('warning');
                } else {
                    $counter.removeClass('warning');
                }
            }
        },
        
        /**
         * Show message
         */
        showMessage: function(message, type) {
            var $messages = $('#form-messages');
            var alertClass = 'notice-' + (type || 'info');
            
            var $notice = $('<div class="notice ' + alertClass + '">' + message + '</div>');
            $messages.html($notice);
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $messages.offset().top - 50
            }, 500);
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            }
        },
        
        /**
         * Validate email format
         */
        isValidEmail: function(email) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },
        
        /**
         * Validate phone format
         */
        isValidPhone: function(phone) {
            var phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
            var cleanPhone = phone.replace(/[\s\-\(\)\.]/g, '');
            return phoneRegex.test(cleanPhone);
        },
        
        /**
         * Format file size
         */
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        /**
         * Get file icon based on extension
         */
        getFileIcon: function(filename) {
            var extension = filename.split('.').pop().toLowerCase();
            var icons = {
                'pdf': '📄',
                'doc': '📝',
                'docx': '📝',
                'jpg': '🖼️',
                'jpeg': '🖼️',
                'png': '🖼️',
                'gif': '🖼️',
                'zip': '📦',
                'rar': '📦'
            };
            
            return icons[extension] || '📎';
        }
    };

    // Status page functionality
    var StatusPage = {
        
        /**
         * Initialize status page
         */
        init: function() {
            this.bindEvents();
            this.initRefresh();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Refresh button
            $(document).on('click', '.refresh-status', this.refreshStatus);
            
            // Auto-refresh for pending payments
            if ($('.status-pending').length > 0) {
                this.startAutoRefresh();
            }
        },
        
        /**
         * Initialize refresh functionality
         */
        initRefresh: function() {
            // Add refresh button if not present
            var $container = $('.priority-ticket-status-container');
            if ($container.length && !$container.find('.refresh-status').length) {
                var $refreshBtn = $('<button type="button" class="btn btn-secondary refresh-status">Refresh Status</button>');
                $container.find('h3').after($refreshBtn);
            }
        },
        
        /**
         * Refresh status
         */
        refreshStatus: function() {
            location.reload();
        },
        
        /**
         * Start auto-refresh for pending statuses
         */
        startAutoRefresh: function() {
            // Auto-refresh every 30 seconds if there are pending items
            setInterval(function() {
                if ($('.status-pending').length > 0) {
                    StatusPage.refreshStatus();
                }
            }, 30000);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        PriorityTicketFrontend.init();
        StatusPage.init();
        
        // Store original button text for restoration
        $('button[type="submit"]').each(function() {
            $(this).data('original-text', $(this).text());
        });
        
        // Smooth scrolling for anchor links
        $('a[href*="#"]:not([href="#"])').click(function() {
            if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '') && location.hostname == this.hostname) {
                var target = $(this.hash);
                target = target.length ? target : $('[name=' + this.hash.slice(1) + ']');
                if (target.length) {
                    $('html, body').animate({
                        scrollTop: target.offset().top - 50
                    }, 1000);
                    return false;
                }
            }
        });
        
        // Add loading overlay functionality
        window.PriorityTicketFrontend = PriorityTicketFrontend;
    });

    // Add some utility functions to window for external access
    window.priorityTicketUtils = {
        formatFileSize: PriorityTicketFrontend.formatFileSize,
        validateEmail: PriorityTicketFrontend.isValidEmail,
        validatePhone: PriorityTicketFrontend.isValidPhone,
        showMessage: PriorityTicketFrontend.showMessage
    };

})(jQuery); 