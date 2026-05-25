sylius_mailer:
    emails:
        {{ mailer_code }}:
            subject: {{ mailer_subject_key }}
            template: 'email/{{ mailer_code }}.html.twig'
