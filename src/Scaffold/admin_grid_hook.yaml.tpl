sylius_twig_hooks:
    hooks:
        'sylius_admin.layout.sidebar.menu.main':
            '{{ grid_name }}':
                template: 'admin/{{ grid_name }}/_sidebar_menu_entry.html.twig'
