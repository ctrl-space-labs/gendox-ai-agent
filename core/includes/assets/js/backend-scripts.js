/*------------------------ 
Backend related javascript
------------------------*/

jQuery(document).ready(function ($) {
    $('#test_connection_button').on('click', function () {
        var apiKey = $('#gendox_api_key').val();
        var connectionStatus = $('#connection_status');

        // Add a Gendox spinner while loading
        connectionStatus.html('<span class="gendox-spinner" style="display: inline-block; margin-left: 10px;"></span>');

        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_test_connection',
                security: gendox.nonce,
                api_key: apiKey
            },
            success: function (response) {
                if (response.success) {
                    if (response.data.status == '200') {
                        connectionStatus.html('<span style="color: green;">✔️ Welcome back, ' + response.data.username + '!</span>');
                    } else {
                        connectionStatus.html('<span style="color: red;">❌ Error ' + response.data.status + ': ' + response.data.message + '</span>');
                    }
                } else {
                    connectionStatus.html('<span style="color: red;">❌ Connection Failed</span>');
                }
            },
            error: function () {
                connectionStatus.html('<span style="color: red;">❌ Connection Failed</span>');
            }
        });
    });
});

jQuery(document).ready(function ($) {
    $('#fetch_projects_button').on('click', function () {
        // Show a confirmation popup
        if (!confirm("Warning: This action may delete data for non-existing projects. Do you want to continue?")) {
            return; // Exit if the user doesn't confirm
        }

        var $projectsList = $('#projects_list');
        $projectsList.html('<tr><td colspan="4">Loading...</td></tr>'); // Show loading message

        // Send AJAX request to fetch projects
        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_fetch_projects',
                security: gendox.nonce
            },
            success: function (response) {
                if (response.success) {
                    var projects = response.data;
                    var projectRows = '';

                    $.each(projects, function (index, project) {
                        projectRows += '<tr>';
                        projectRows += '<td>' + project.gendox_id + '</td>';
                        projectRows += '<td>' + project.name + '</td>';
                        projectRows += '<td>' + (project.description || 'No description') + '</td>';
                        projectRows += '<td>' +
                            '<a href="#" class="btn btn-sm btn-warning edit-project" title="Assign Content"><i class="fas fa-pencil-alt"></i> Assign Content</a> ' +
                            '<a href="#" class="btn btn-sm btn-success assign-chat" title="Assign Chat" data-project-id="' + project.gendox_id + '"><i class="fas fa-eye"></i> Assign Chat</a> ' +
                            '<a href="#" class="btn btn-sm btn-info view-project" title="View"><i class="fas fa-eye"></i> View</a> ' +
                            '<a href="#" class="btn btn-sm btn-danger delete-project" title="Delete"><i class="fas fa-trash"></i> Delete</a> ' +
                            '</td>';
                        projectRows += '</tr>';
                    });

                    $projectsList.html(projectRows);
                } else {
                    $projectsList.html('<tr><td colspan="4">No projects found.</td></tr>');
                }
            },
            error: function () {
                $projectsList.html('<tr><td colspan="4">Error fetching projects.</td></tr>');
            }
        });
    });
});


jQuery(document).ready(function ($) {

    // Open Edit Modal
    $(document).on('click', '.edit-project', function (e) {
        e.preventDefault();

        var projectId = $(this).closest('tr').find('td:first').text();
        $('#projectId').val(projectId);

        // Open Modal
        $('#editProjectModal').show();

        // Show loader while data is being loaded
        $('#postList, #productList, #pageList').html('<div class="loader"></div>');

        // Fetch Posts, Products, Pages via AJAX and populate the selects
        var postsLoaded = loadItems('posts', '#postList');
        var productsLoaded = loadItems('products', '#productList');
        var pagesLoaded = loadItems('pages', '#pageList');

        // When all data has loaded, fetch saved project data and pre-select the items
        $.when(postsLoaded, productsLoaded, pagesLoaded).done(function () {
            setTimeout(function () {
                // Fetch the saved project details and pre-select the options
                $.ajax({
                    url: gendox.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'gendox_get_project_items',
                        security: gendox.nonce,
                        projectId: projectId
                    },
                    success: function (response) {
                        if (response.success) {
                            // Pre-select the posts, products, and pages
                            preselectItems('posts', response.data.posts);
                            preselectItems('products', response.data.products);
                            preselectItems('pages', response.data.pages);
                        }
                    }
                });
            }, 1000); // Delay execution by 500ms
        });
    });

    // Preselect the saved items in Select2
    function preselectItems(itemType, selectedItems) {
        //timeout to wait for select2 to initialize
        console.log('preselecting items');
        var selectElement = '#assign_' + itemType;
        console.log(selectedItems);
        $(selectElement).val(selectedItems).trigger('change'); // Preselect items in Select2
    }

    // Function to load posts, products, and pages via AJAX and populate multi-selects
    function loadItems(itemType, selectElement) {
        console.log('loading items');
        var deferred = $.Deferred(); // Use deferred to track when data is fully loaded

        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_fetch_' + itemType,
                security: gendox.nonce
            },
            success: function (response) {
                if (response.success) {
                    var options = '';
                    $.each(response.data, function (index, item) {
                        options += '<option value="' + item.id + '">' + item.title + '</option>';
                    });
                    $(selectElement).html('<select id="assign_' + itemType + '" class="select2-multi" multiple>' + options + '</select>'); // Update select

                    // Initialize Select2 after loading items
                    $(selectElement + ' .select2-multi').select2();
                    console.log(response.data);
                    deferred.resolve(); // Resolve deferred when data is loaded
                } else {
                    $(selectElement).html('<option>No ' + itemType + ' found</option>').trigger('change');
                    deferred.resolve(); // Resolve even if no data is found
                }
            },
            error: function () {
                $(selectElement).html('<option>Error loading ' + itemType + '</option>');
                deferred.resolve(); // Resolve in case of an error
            }
        });
        return deferred.promise(); // Return the deferred promise
    }

    // Save Changes
    $('#saveProjectChanges').on('click', function () {
        var projectId = $('#projectId').val();
        var selectedPosts = $('#assign_posts').val();
        var selectedProducts = $('#assign_products').val();
        var selectedPages = $('#assign_pages').val();
        console.log(selectedPosts);
        console.log(selectedProducts);
        console.log(selectedPages);

        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_save_project_changes',
                security: gendox.nonce,
                projectId: projectId,
                posts: selectedPosts,
                products: selectedProducts,
                pages: selectedPages
            },
            success: function (response) {
                if (response.success) {
                    alert('Changes saved successfully!');
                    $('#editProjectModal').hide();
                } else {
                    alert('Error saving changes.');
                }
            }
        });
    });

    // Close Modal
    $('#closeModal').on('click', function () {
        $('#editProjectModal').hide();
    });

    // View Project Modal
    $(document).on('click', '.view-project', function (e) {
        e.preventDefault();

        var projectId = $(this).closest('tr').find('td:first').text();

        // Send AJAX request to fetch the project details
        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_view_project',
                security: gendox.nonce,
                projectId: projectId
            },
            success: function (response) {
                if (response.success) {
                    var data = response.data;
                    // Populate the modal with the project details
                    $('#view_projectId').text(projectId);
                    $('#view_projectName').text(data.name || 'N/A');
                    $('#view_projectDescription').text(data.description || 'No description');

                    // Populate the assigned items inside a <span> for each item
                    $('#view_assignedPosts').html(
                        data.posts && data.posts.length
                            ? data.posts.map(post => `<span>${post}</span>`)
                            : '<span>No posts assigned</span>'
                    );

                    $('#view_assignedProducts').html(
                        data.products && data.products.length
                            ? data.products.map(product => `<span>${product}</span>`)
                            : '<span>No products assigned</span>'
                    );

                    $('#view_assignedPages').html(
                        data.pages && data.pages.length
                            ? data.pages.map(page => `<span>${page}</span>`)
                            : '<span>No pages assigned</span>'
                    );

                    // Show the modal
                    $('#viewProjectModal').show();
                } else {
                    alert('Project details could not be loaded.');
                }
            },
            error: function () {
                alert('Error fetching project details.');
            }
        });
    });

    // Close the View Modal
    $('#closeViewModal').on('click', function () {
        $('#viewProjectModal').hide();
    });

});

jQuery(document).ready(function ($) {
    // Function to populate the Quick Edit fields with checkboxes
    function setQuickEditValues(post_id) {
        // uncheck each checkbox first
        $('input[name="assigned_projects[]"]').each(function () {
            $(this).prop('checked', false);
        });
        // Get the assigned project IDs as an array from the data attributes in the column
        var assigned_projects = [];
        $('#post-' + post_id + ' .column-assigned_project span').each(function () {
            assigned_projects.push($(this).attr('data-gendoxId'));
        });

        console.log("Assigned Projects:", assigned_projects); // Debug: Check the project IDs array

        // Uncheck all checkboxes first to reset
        $('input[name="assigned_project[]"]').prop('checked', false);

        // Check the checkboxes that match the assigned project IDs
        assigned_projects.forEach(function (projectId) {
            $('input[name="assigned_projects[]"][value="' + projectId + '"]').prop('checked', true);
        });
    }

    // Hook into the quick edit button click
    $(document).on('click', '.editinline', function () {
        var post_id = $(this).closest('tr').attr('id').replace('post-', '');
        console.log("Post ID:", post_id); // Debug: Check the post ID
        setQuickEditValues(post_id);
    });
});

jQuery(document).ready(function ($) {
    $(document).on('click', '.assign-chat', function (e) {
        e.preventDefault();
        // deselect all options
        $('select[id^="taxonomy_"] option').prop('selected', false);
        $('select[id^="taxonomy_"]').trigger('change');
        // deselect all post types options
        $('#postTypeSelect option').prop('selected', false);
        $('#postTypeSelect').trigger('change');
        const projectId = $(this).closest('tr').find('.assign-chat').data('project-id');
        $('#projectId').val(projectId); // Set the project ID in the hidden input

        // Fetch saved chat settings for the project
        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_get_chat_settings',
                security: gendox.nonce,
                project_id: projectId
            },
            success: function (response) {
                if (response.success) {
                    const settings = response.data;

                    // Preselect post types
                    $('#postTypeSelect').val(settings.post_type).trigger('change');

                    // Preselect taxonomy terms for each taxonomy
                    $.each(settings.taxonomies, function (taxonomy, termIds) {
                        $(`#taxonomy_${taxonomy}`).val(termIds).trigger('change');
                    });

                    // Show the modal for selecting post type and taxonomy
                    $('#assignChatModal').show();
                } else {
                    alert('Could not load chat settings.');
                }
            },
            error: function () {
                alert('Error loading chat settings.');
            }
        });
    });

    // Save chat settings
    $('#saveChatSettings').on('click', function () {
        const projectId = $('#projectId').val();
        const postType = $('#postTypeSelect').val();
        const taxonomySelections = {};

        // Gather selected terms for each taxonomy
        $('select[id^="taxonomy_"]').each(function () {
            const taxonomy = $(this).attr('id').replace('taxonomy_', ''); // Extract taxonomy name
            taxonomySelections[taxonomy] = $(this).val(); // Store selected terms as an array
        });

        $.ajax({
            url: gendox.ajax_url,
            type: 'POST',
            data: {
                action: 'gendox_save_chat_settings',
                security: gendox.nonce,
                project_id: projectId,
                post_type: postType,
                taxonomies: taxonomySelections
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data); // Notify the user
                    $('#assignChatModal').hide(); // Close modal
                } else {
                    alert('Error saving chat settings.');
                }
            },
            error: function () {
                alert('Error saving chat settings.');
            }
        });
    });

    // Close modal
    $('#closeChatModal').on('click', function () {
        $('#assignChatModal').hide();
    });

    $('select[id^="taxonomy_"]').select2({
        placeholder: 'Select terms',
        width: '100%'
    });

    $('#postTypeSelect').select2({
        placeholder: 'Select post type',
        width: '100%'
    });
});

jQuery(document).ready(function ($) {
    $('#generate_wp_api_key').on('click', function () {
        // Show loading message
        $('#wp_api_key_message').text('Generating API Key...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'generate_wp_gendox_api_key'
            },
            success: function (response) {
                if (response.success) {
                    $('#wp_gendox_api_key').val(response.data); // Set the generated key in the field
                    $('#wp_api_key_message').text('API Key generated successfully.');
                } else {
                    $('#wp_api_key_message').text('Failed to generate API Key.');
                }
            },
            error: function () {
                $('#wp_api_key_message').text('Error generating API Key.');
            }
        });
    });
});

