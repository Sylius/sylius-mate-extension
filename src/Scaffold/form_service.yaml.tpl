services:
    app.form.type.{{ block_prefix }}:
        class: {{ namespace }}\Form\Type\{{ model }}Type
        arguments:
            - {{ namespace }}\Entity\{{ model }}
            - ['sylius']
        tags:
            - { name: form.type }

    {{ namespace }}\Form\Type\{{ model }}Type:
        alias: app.form.type.{{ block_prefix }}

    {{ namespace }}\Repository\{{ model }}RepositoryInterface:
        alias: {{ alias }}.repository

    {{ namespace }}\Factory\{{ model }}FactoryInterface:
        alias: {{ alias }}.factory
