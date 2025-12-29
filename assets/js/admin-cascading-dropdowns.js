/**
 * Cascading Dropdown Filters for Admin CPT Edit Screens
 *
 * Handles:
 * - Service Request: Filter "Submitted By" contacts by selected Organization
 * - Invoice: Filter "Related Project" by selected Organization
 * - Invoice: Filter "Related Milestone" by selected Project
 *
 * Phase 8.4b
 */
(function($) {
    'use strict';

    var CascadingDropdowns = {
        /**
         * Initialize based on current screen.
         */
        init: function() {
            // Wait for DOM and Pods to be ready
            $(document).ready(function() {
                CascadingDropdowns.setupHandlers();
            });
        },

        /**
         * Setup event handlers based on post type.
         */
        setupHandlers: function() {
            var postType = $('#post_type').val();

            if (postType === 'service_request') {
                CascadingDropdowns.setupSRSubmittedBy();
                CascadingDropdowns.setupSRProject();
            } else if (postType === 'invoice') {
                CascadingDropdowns.setupInvoiceProject();
                CascadingDropdowns.setupInvoiceMilestone();
            } else if (postType === 'time_entry') {
                CascadingDropdowns.setupTEProject();
            }
        },

        /**
         * SR Screen: Filter "Submitted By" by Organization.
         */
        setupSRSubmittedBy: function() {
            var $orgField = $('select[name="pods_meta_organization"]');
            var $submittedByField = $('select[name="pods_meta_submitted_by"]');

            if (!$orgField.length || !$submittedByField.length) {
                // Try alternative selectors for Pods
                $orgField = $('#pods-form-ui-service_request-organization');
                $submittedByField = $('#pods-form-ui-service_request-submitted_by');
            }

            if (!$orgField.length || !$submittedByField.length) {
                return;
            }

            // Store original options
            var originalOptions = $submittedByField.find('option').clone();

            $orgField.on('change', function() {
                var orgId = $(this).val();

                if (!orgId) {
                    // Reset to all options
                    $submittedByField.html(originalOptions.clone());
                    return;
                }

                // Show loading state
                $submittedByField.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'bbab_get_org_contacts',
                    org_id: orgId,
                    nonce: bbabCascading.nonce
                }, function(response) {
                    $submittedByField.prop('disabled', false);

                    if (response.success && response.data.contacts) {
                        var currentVal = $submittedByField.val();
                        $submittedByField.empty();
                        $submittedByField.append('<option value="">— Select —</option>');

                        $.each(response.data.contacts, function(id, name) {
                            var selected = (id == currentVal) ? ' selected' : '';
                            $submittedByField.append('<option value="' + id + '"' + selected + '>' + name + '</option>');
                        });
                    }
                }).fail(function() {
                    $submittedByField.prop('disabled', false);
                });
            });
        },

        /**
         * SR Screen: Filter "Related Project" by Organization.
         */
        setupSRProject: function() {
            var $orgField = $('select[name="pods_meta_organization"]');
            var $projectField = $('select[name="pods_meta_related_project"]');

            if (!$orgField.length || !$projectField.length) {
                $orgField = $('#pods-form-ui-service_request-organization');
                $projectField = $('#pods-form-ui-service_request-related_project');
            }

            if (!$orgField.length || !$projectField.length) {
                return;
            }

            var originalOptions = $projectField.find('option').clone();

            $orgField.on('change', function() {
                var orgId = $(this).val();

                if (!orgId) {
                    $projectField.html(originalOptions.clone());
                    return;
                }

                $projectField.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'bbab_cascade_get_projects',
                    org_id: orgId,
                    nonce: bbabCascading.nonce
                }, function(response) {
                    $projectField.prop('disabled', false);

                    if (response.success && response.data.projects) {
                        var currentVal = $projectField.val();
                        $projectField.empty();
                        $projectField.append('<option value="">— Select —</option>');

                        $.each(response.data.projects, function(id, name) {
                            var selected = (id == currentVal) ? ' selected' : '';
                            $projectField.append('<option value="' + id + '"' + selected + '>' + name + '</option>');
                        });
                    }
                }).fail(function() {
                    $projectField.prop('disabled', false);
                });
            });
        },

        /**
         * Time Entry Screen: Filter "Related Project" by Organization.
         */
        setupTEProject: function() {
            // Time entries get org from SR or directly
            // Look for organization field or derive from related_service_request
            var $srField = $('select[name="pods_meta_related_service_request"]');
            var $projectField = $('select[name="pods_meta_related_project"]');

            if (!$srField.length || !$projectField.length) {
                $srField = $('#pods-form-ui-time_entry-related_service_request');
                $projectField = $('#pods-form-ui-time_entry-related_project');
            }

            if (!$projectField.length) {
                return;
            }

            // If there's a direct org field on TE, use that
            var $orgField = $('select[name="pods_meta_organization"]');
            if (!$orgField.length) {
                $orgField = $('#pods-form-ui-time_entry-organization');
            }

            if ($orgField.length) {
                var originalOptions = $projectField.find('option').clone();

                $orgField.on('change', function() {
                    var orgId = $(this).val();

                    if (!orgId) {
                        $projectField.html(originalOptions.clone());
                        return;
                    }

                    $projectField.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'bbab_cascade_get_projects',
                        org_id: orgId,
                        nonce: bbabCascading.nonce
                    }, function(response) {
                        $projectField.prop('disabled', false);

                        if (response.success && response.data.projects) {
                            var currentVal = $projectField.val();
                            $projectField.empty();
                            $projectField.append('<option value="">— Select —</option>');

                            $.each(response.data.projects, function(id, name) {
                                var selected = (id == currentVal) ? ' selected' : '';
                                $projectField.append('<option value="' + id + '"' + selected + '>' + name + '</option>');
                            });
                        }
                    }).fail(function() {
                        $projectField.prop('disabled', false);
                    });
                });
            }
        },

        /**
         * Invoice Screen: Filter "Related Project" by Organization.
         */
        setupInvoiceProject: function() {
            // Find the organization field - try multiple patterns
            var $orgField = null;
            var $projectField = null;

            // Pattern 1: Standard Pods meta naming
            $orgField = $('select[name="pods_meta_organization"]');
            $projectField = $('select[name="pods_meta_related_project"]');

            // Pattern 2: Try finding by label text (look for "Client" or "Organization" labels)
            if (!$orgField.length) {
                $('.pods-form-front-row, .pods-field').each(function() {
                    var $row = $(this);
                    var labelText = $row.find('label').text().toLowerCase();
                    if (labelText.indexOf('client') !== -1 || labelText.indexOf('organization') !== -1) {
                        var $select = $row.find('select');
                        if ($select.length) {
                            $orgField = $select;
                            return false; // break
                        }
                    }
                });
            }

            // Pattern 3: Any select with organization in name or id
            if (!$orgField || !$orgField.length) {
                $orgField = $('select[name*="organization"], select[id*="organization"]').first();
            }
            if (!$projectField || !$projectField.length) {
                $projectField = $('select[name*="related_project"], select[id*="related_project"]').first();
            }

            // Pattern 4: Pods autocomplete hidden input (if org is autocomplete field)
            if (!$orgField || !$orgField.length) {
                // Look for hidden input that stores the relationship value
                $orgField = $('input[type="hidden"][name*="organization"]').first();
            }

            if (!$orgField || !$orgField.length || !$projectField || !$projectField.length) {
                return;
            }

            // Store original options
            var originalOptions = $projectField.find('option').clone();

            // Handle change events
            $orgField.on('change', function() {
                var orgId = $(this).val();

                if (!orgId) {
                    $projectField.html(originalOptions.clone());
                    return;
                }

                $projectField.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'bbab_cascade_get_projects',
                    org_id: orgId,
                    nonce: bbabCascading.nonce
                }, function(response) {
                    $projectField.prop('disabled', false);

                    if (response.success && response.data.projects) {
                        var currentVal = $projectField.val();
                        $projectField.empty();
                        $projectField.append('<option value="">— Select —</option>');

                        $.each(response.data.projects, function(id, name) {
                            var selected = (id == currentVal) ? ' selected' : '';
                            $projectField.append('<option value="' + id + '"' + selected + '>' + name + '</option>');
                        });

                        // Trigger milestone filter update
                        $projectField.trigger('change');
                    }
                }).fail(function() {
                    $projectField.prop('disabled', false);
                });
            });
        },

        /**
         * Invoice Screen: Filter "Related Milestone" by Project.
         */
        setupInvoiceMilestone: function() {
            var $projectField = $('select[name="pods_meta_related_project"]');
            var $milestoneField = $('select[name="pods_meta_related_milestone"]');

            if (!$projectField.length || !$milestoneField.length) {
                // Try alternative selectors
                $projectField = $('#pods-form-ui-invoice-related_project');
                $milestoneField = $('#pods-form-ui-invoice-related_milestone');
            }

            if (!$projectField.length || !$milestoneField.length) {
                return;
            }

            // Store original options
            var originalOptions = $milestoneField.find('option').clone();

            $projectField.on('change', function() {
                var projectId = $(this).val();

                if (!projectId) {
                    $milestoneField.html(originalOptions.clone());
                    return;
                }

                $milestoneField.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'bbab_get_project_milestones',
                    project_id: projectId,
                    nonce: bbabCascading.nonce
                }, function(response) {
                    $milestoneField.prop('disabled', false);

                    if (response.success && response.data.milestones) {
                        var currentVal = $milestoneField.val();
                        $milestoneField.empty();
                        $milestoneField.append('<option value="">— Select —</option>');

                        $.each(response.data.milestones, function(id, name) {
                            var selected = (id == currentVal) ? ' selected' : '';
                            $milestoneField.append('<option value="' + id + '"' + selected + '>' + name + '</option>');
                        });
                    }
                }).fail(function() {
                    $milestoneField.prop('disabled', false);
                });
            });
        }
    };

    // Initialize
    CascadingDropdowns.init();

})(jQuery);
