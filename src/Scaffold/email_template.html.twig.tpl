{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {%- set translation_locale = localeCode|default('en') -%}
    {{- 'app.email.{{ code }}.subject'|trans({}, 'messages', translation_locale) -}}
{% endblock %}

{% block content %}
    {% set translation_locale = localeCode|default('en') %}
    {# body — reference context vars: {{ context_vars }} #}
{% endblock %}
