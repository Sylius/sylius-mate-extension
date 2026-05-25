sylius_grid:
    grids:
        {{ grid_name }}:
            driver:
                name: doctrine/orm
                options:
                    class: {{ namespace }}\Entity\{{ model }}
            fields:
                id:
                    type: string
                    label: sylius.ui.id
            actions:
                main:
                    create:
                        type: create
                item:
                    update:
                        type: update
                    delete:
                        type: delete
