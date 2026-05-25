sylius_resource:
    resources:
        {{ alias }}:
            classes:
                model: {{ namespace }}\Entity\{{ model }}
                interface: {{ namespace }}\Entity\{{ model }}Interface
                repository: {{ namespace }}\Repository\{{ model }}Repository
                factory: {{ namespace }}\Factory\{{ model }}Factory
                form: {{ namespace }}\Form\Type\{{ model }}Type
