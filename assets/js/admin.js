/**
 * Admin JavaScript for Priority Ticket Payment
 */

(function($) {
    'use strict';

    // Admin functionality
    var PriorityTicketAdmin = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initModals();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // View submission button
            $(document).on('click', '.view-submission', this.viewSubmission);
            
            // Delete submission button
            $(document).on('click', '.delete-submission', this.deleteSubmission);
            
            // Status filter
            $(document).on('change', '#status-filter', this.filterByStatus);
            
            // Bulk actions
            $(document).on('click', '#doaction', this.handleBulkAction);
            $(document).on('click', '#doaction2', this.handleBulkAction);
            
            // Settings form validation
            $(document).on('submit', '.priority-ticket-settings-form', this.validateSettings);
            
            // Export functionality
            $(document).on('click', '.export-submissions', this.exportSubmissions);
        },
        
        /**
         * Initialize modals
         */
        initModals: function() {
            // Create modal if it doesn't exist
            if ($('#priority-ticket-modal').length === 0) {
                $('body').append(this.getModalHTML());
            }
            
            // Close modal events
            $(document).on('click', '.priority-ticket-modal-close', this.closeModal);
            $(document).on('click', '.priority-ticket-modal', function(e) {
                if (e.target === this) {
                    PriorityTicketAdmin.closeModal();
                }
            });
            
            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) {
                    PriorityTicketAdmin.closeModal();
                }
            });
        },
        
        /**
         * View submission details
         */
        viewSubmission: function(e) {
            e.preventDefault();
            
            var submissionId = $(this).data('id');
            var $button = $(this);
            
            // Add loading state
            $button.prop('disabled', true).text('Loading...');
            
            $.ajax({
                url: priority_ticket_payment_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'priority_ticket_payment_view_submission',
                    submission_id: submissionId,
                    nonce: priority_ticket_payment_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PriorityTicketAdmin.showSubmissionModal(response.data);
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while loading the submission details.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('View');
                }
            });
        },
        
        /**
         * Delete submission
         */
        deleteSubmission: function(e) {
            e.preventDefault();
            
            var submissionId = $(this).data('id');
            var $button = $(this);
            var $row = $button.closest('tr');
            
            if (!confirm('Are you sure you want to delete this submission? This action cannot be undone.')) {
                return;
            }
            
            // Add loading state
            $button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: priority_ticket_payment_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'priority_ticket_payment_delete_submission',
                    submission_id: submissionId,
                    nonce: priority_ticket_payment_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        PriorityTicketAdmin.showNotice('Submission deleted successfully.', 'success');
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while deleting the submission.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Delete');
                }
            });
        },
        
        /**
         * Filter submissions by status
         */
        filterByStatus: function() {
            var status = $(this).val();
            var url = new URL(window.location);
            
            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            
            url.searchParams.delete('paged');
            window.location.href = url.toString();
        },
        
        /**
         * Handle bulk actions
         */
        handleBulkAction: function(e) {
            var action = $(this).prev('select').val();
            var checkedBoxes = $('input[name="submission[]"]:checked');
            
            if (action === '-1') {
                e.preventDefault();
                alert('Please select an action.');
                return;
            }
            
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one submission.');
                return;
            }
            
            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete the selected submissions? This action cannot be undone.')) {
                    e.preventDefault();
                    return;
                }
            }
        },
        
        /**
         * Validate settings form
         */
        validateSettings: function(e) {
            var valid = true;
            var errors = [];
            
            // Validate price
            var price = $('input[name="priority_ticket_payment_options[default_ticket_price]"]').val();
            if (price && (isNaN(price) || parseFloat(price) < 0)) {
                errors.push('Default ticket price must be a valid number.');
                valid = false;
            }
            
            // Validate max file size
            var maxFileSize = $('input[name="priority_ticket_payment_options[max_file_size]"]').val();
            if (maxFileSize && (isNaN(maxFileSize) || parseInt(maxFileSize) < 1)) {
                errors.push('Maximum file size must be a positive number.');
                valid = false;
            }
            
            if (!valid) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            }
        },
        
        /**
         * Export submissions
         */
        exportSubmissions: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var format = $button.data('format') || 'csv';
            
            // Add loading state
            $button.prop('disabled', true).append(' <span class="loading-spinner"></span>');
            
            // Create download URL
            var url = new URL(window.location);
            url.searchParams.set('action', 'export_submissions');
            url.searchParams.set('format', format);
            url.searchParams.set('nonce', priority_ticket_payment_ajax.nonce);
            
            // Create hidden iframe for download
            var iframe = $('<iframe>')
                .attr('src', url.toString())
                .css('display', 'none')
                .appendTo('body');
            
            // Remove loading state after delay
            setTimeout(function() {
                $button.prop('disabled', false).find('.loading-spinner').remove();
                iframe.remove();
            }, 3000);
        },
        
        /**
         * Show submission modal
         */
        showSubmissionModal: function(data) {
            var submission = data.submission;
            var user = data.user;
            var formData = submission.form_data;
            var attachments = submission.attachments || [];
            
            var content = '<div class="submission-details">';
            content += '<h4>Submission Details</h4>';
            content += '<table class="form-table">';
            content += '<tr><th>ID</th><td>#' + submission.id + '</td></tr>';
            content += '<tr><th>User</th><td>' + user.display_name + ' (' + user.email + ')</td></tr>';
            content += '<tr><th>Subject</th><td>' + (formData.ticket_subject || 'N/A') + '</td></tr>';
            content += '<tr><th>Priority</th><td>' + (formData.ticket_priority || 'N/A') + '</td></tr>';
            content += '<tr><th>Category</th><td>' + (formData.ticket_category || 'N/A') + '</td></tr>';
            content += '<tr><th>Price</th><td>' + data.formatted_price + '</td></tr>';
            content += '<tr><th>Status</th><td><span class="status-badge status-' + submission.payment_status + '">' + submission.payment_status.charAt(0).toUpperCase() + submission.payment_status.slice(1) + '</span></td></tr>';
            content += '<tr><th>Date</th><td>' + data.formatted_date + '</td></tr>';
            
            if (submission.order_id) {
                content += '<tr><th>Order ID</th><td>' + submission.order_id + '</td></tr>';
            }
            
            content += '</table>';
            
            if (formData.ticket_description) {
                content += '<h4>Description</h4>';
                content += '<div class="submission-description">' + formData.ticket_description.replace(/\n/g, '<br>') + '</div>';
            }
            
            if (attachments.length > 0) {
                content += '<h4>Attachments</h4>';
                content += '<div class="priority-ticket-attachments">';
                attachments.forEach(function(attachment) {
                    content += '<div class="priority-ticket-attachment">';
                    content += '<span class="priority-ticket-attachment-icon">📎</span>';
                    content += '<span class="priority-ticket-attachment-name">' + attachment.original_name + '</span>';
                    content += '<span class="priority-ticket-attachment-size">(' + PriorityTicketAdmin.formatFileSize(attachment.size) + ')</span>';
                    content += '</div>';
                });
                content += '</div>';
            }
            
            if (formData.contact_email || formData.contact_phone) {
                content += '<h4>Contact Information</h4>';
                content += '<table class="form-table">';
                if (formData.contact_email) {
                    content += '<tr><th>Email</th><td>' + formData.contact_email + '</td></tr>';
                }
                if (formData.contact_phone) {
                    content += '<tr><th>Phone</th><td>' + formData.contact_phone + '</td></tr>';
                }
                content += '</table>';
            }
            
            content += '</div>';
            
            // Update modal content
            $('#priority-ticket-modal .priority-ticket-modal-title').text('Submission #' + submission.id);
            $('#priority-ticket-modal .priority-ticket-modal-body').html(content);
            
            // Show modal
            $('#priority-ticket-modal').fadeIn(300);
        },
        
        /**
         * Close modal
         */
        closeModal: function() {
            $('#priority-ticket-modal').fadeOut(300);
        },
        
        /**
         * Get modal HTML
         */
        getModalHTML: function() {
            return '<div id="priority-ticket-modal" class="priority-ticket-modal">' +
                   '<div class="priority-ticket-modal-content">' +
                   '<div class="priority-ticket-modal-header">' +
                   '<h3 class="priority-ticket-modal-title">Submission Details</h3>' +
                   '<button class="priority-ticket-modal-close">&times;</button>' +
                   '</div>' +
                   '<div class="priority-ticket-modal-body"></div>' +
                   '<div class="priority-ticket-modal-footer">' +
                   '<button type="button" class="button priority-ticket-modal-close">Close</button>' +
                   '</div>' +
                   '</div>' +
                   '</div>';
        },
        
        /**
         * Show admin notice
         */
        showNotice: function(message, type) {
            type = type || 'info';
            var notice = $('<div class="notice notice-' + type + ' is-dismissible priority-ticket-notice">' +
                          '<p>' + message + '</p>' +
                          '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' +
                          '</div>');
            
            $('.wrap h1').after(notice);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
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
         * Initialize charts (if needed)
         */
        initCharts: function() {
            // Placeholder for future chart implementation
            // This could be used for displaying statistics
        }
    };

    // Dashboard widget functionality
    var DashboardWidget = {
        
        /**
         * Initialize dashboard widget
         */
        init: function() {
            this.loadStats();
            this.bindEvents();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Refresh stats button
            $(document).on('click', '.refresh-stats', this.loadStats);
        },
        
        /**
         * Load statistics
         */
        loadStats: function() {
            var $container = $('.priority-ticket-stats');
            if ($container.length === 0) return;
            
            $container.html('<div class="loading-spinner"></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_priority_ticket_stats',
                    nonce: priority_ticket_payment_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        DashboardWidget.renderStats(response.data);
                    }
                },
                error: function() {
                    $container.html('<p>Error loading statistics.</p>');
                }
            });
        },
        
        /**
         * Render statistics
         */
        renderStats: function(stats) {
            var html = '';
            
            Object.keys(stats).forEach(function(key) {
                var stat = stats[key];
                html += '<div class="priority-ticket-stat-card">';
                html += '<div class="priority-ticket-stat-number">' + stat.value + '</div>';
                html += '<div class="priority-ticket-stat-label">' + stat.label + '</div>';
                html += '</div>';
            });
            
            $('.priority-ticket-stats').html(html);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        PriorityTicketAdmin.init();
        DashboardWidget.init();
        
        // Initialize tooltips if available
        if (typeof $.fn.tooltip === 'function') {
            $('[data-tooltip]').tooltip();
        }
        
        // Initialize WordPress color picker if available
        if (typeof $.fn.wpColorPicker === 'function') {
            $('.color-picker').wpColorPicker();
        }
        
        // Initialize WordPress media uploader if needed
        if (typeof wp !== 'undefined' && wp.media) {
            // Media uploader functionality can be added here
        }
    });

})(jQuery); 