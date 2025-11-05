<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-Language URL Pattern Matching
    |--------------------------------------------------------------------------
    |
    | This configuration defines URL patterns for different languages to help
    | identify contact pages, about pages, and other important sections across
    | multilingual websites.
    |
    */

    'contact_page' => [
        // English
        'en' => [
            'contact', 'contact-us', 'contactus', 'get-in-touch', 'reach-us',
            'contact-form', 'contact-page', 'contacto', 'contacts',
        ],
        // Dutch
        'nl' => [
            'contact', 'contacteer-ons', 'contacteer', 'neem-contact-op',
            'contact-opnemen', 'contactformulier', 'contactpagina',
            'bereik-ons', 'get-in-touch',
        ],
        // German
        'de' => [
            'kontakt', 'kontaktieren', 'kontaktformular', 'kontaktseite',
            'kontaktiere-uns', 'kontakt-aufnehmen',
        ],
        // French
        'fr' => [
            'contact', 'contactez-nous', 'nous-contacter', 'formulaire-contact',
            'page-contact', 'contactez',
        ],
        // Spanish
        'es' => [
            'contacto', 'contactanos', 'contacta-con-nosotros', 'formulario-contacto',
            'pagina-contacto', 'contactar',
        ],
        // Italian
        'it' => [
            'contatto', 'contattaci', 'contatta', 'modulo-contatto',
            'pagina-contatto', 'contattare',
        ],
    ],

    'about_page' => [
        // English
        'en' => [
            'about', 'about-us', 'aboutus', 'about-me', 'who-we-are',
            'our-story', 'our-team', 'company', 'about-company',
        ],
        // Dutch
        'nl' => [
            'over', 'over-ons', 'overons', 'wie-zijn-wij', 'wie-we-zijn',
            'ons-verhaal', 'ons-team', 'bedrijf', 'over-het-bedrijf',
            'het-team', 'organisatie',
        ],
        // German
        'de' => [
            'uber', 'uber-uns', 'uberuns', 'wir', 'unser-team',
            'unsere-geschichte', 'firma', 'unternehmen',
        ],
        // French
        'fr' => [
            'a-propos', 'apropos', 'qui-sommes-nous', 'notre-histoire',
            'notre-equipe', 'entreprise', 'societe',
        ],
        // Spanish
        'es' => [
            'acerca', 'acerca-de', 'sobre-nosotros', 'quienes-somos',
            'nuestro-equipo', 'nuestra-historia', 'empresa', 'compania',
        ],
        // Italian
        'it' => [
            'chi-siamo', 'su-di-noi', 'la-nostra-storia', 'il-nostro-team',
            'azienda', 'societa',
        ],
    ],

    'team_page' => [
        // English
        'en' => [
            'team', 'our-team', 'the-team', 'meet-the-team', 'staff',
            'people', 'leadership', 'management',
        ],
        // Dutch
        'nl' => [
            'team', 'ons-team', 'het-team', 'medewerkers', 'personeel',
            'mensen', 'leiderschap', 'management', 'staf',
        ],
        // German
        'de' => [
            'team', 'unser-team', 'mitarbeiter', 'personal',
            'fuhrung', 'management', 'leitung',
        ],
        // French
        'fr' => [
            'equipe', 'notre-equipe', 'personnel', 'collaborateurs',
            'direction', 'management',
        ],
        // Spanish
        'es' => [
            'equipo', 'nuestro-equipo', 'personal', 'empleados',
            'liderazgo', 'gestion',
        ],
        // Italian
        'it' => [
            'team', 'squadra', 'il-nostro-team', 'personale',
            'collaboratori', 'dirigenza',
        ],
    ],

    'services_page' => [
        // English
        'en' => [
            'services', 'our-services', 'what-we-do', 'solutions',
            'products', 'offerings',
        ],
        // Dutch
        'nl' => [
            'diensten', 'onze-diensten', 'wat-wij-doen', 'oplossingen',
            'producten', 'aanbod', 'service',
        ],
        // German
        'de' => [
            'dienstleistungen', 'unsere-dienstleistungen', 'leistungen',
            'losungen', 'produkte', 'angebot',
        ],
        // French
        'fr' => [
            'services', 'nos-services', 'prestations', 'solutions',
            'produits', 'offres',
        ],
        // Spanish
        'es' => [
            'servicios', 'nuestros-servicios', 'soluciones',
            'productos', 'ofertas',
        ],
        // Italian
        'it' => [
            'servizi', 'i-nostri-servizi', 'soluzioni',
            'prodotti', 'offerte',
        ],
    ],

    'privacy_page' => [
        // English
        'en' => [
            'privacy', 'privacy-policy', 'privacy-statement', 'data-protection',
        ],
        // Dutch
        'nl' => [
            'privacy', 'privacybeleid', 'privacyverklaring', 'gegevensbescherming',
            'privacystatement', 'avg', 'privacy-policy',
        ],
        // German
        'de' => [
            'datenschutz', 'datenschutzerklarung', 'privatsphare',
        ],
        // French
        'fr' => [
            'confidentialite', 'politique-de-confidentialite', 'protection-des-donnees',
        ],
        // Spanish
        'es' => [
            'privacidad', 'politica-de-privacidad', 'proteccion-de-datos',
        ],
        // Italian
        'it' => [
            'privacy', 'politica-privacy', 'protezione-dati',
        ],
    ],

    'terms_page' => [
        // English
        'en' => [
            'terms', 'terms-and-conditions', 'terms-of-service', 'tos',
            'legal', 'terms-of-use',
        ],
        // Dutch
        'nl' => [
            'voorwaarden', 'algemene-voorwaarden', 'gebruiksvoorwaarden',
            'servicevoorwaarden', 'juridisch', 'terms',
        ],
        // German
        'de' => [
            'agb', 'nutzungsbedingungen', 'allgemeine-geschaftsbedingungen',
            'rechtliches',
        ],
        // French
        'fr' => [
            'conditions', 'conditions-generales', 'conditions-utilisation',
            'mentions-legales',
        ],
        // Spanish
        'es' => [
            'terminos', 'terminos-y-condiciones', 'condiciones-de-uso',
            'legal',
        ],
        // Italian
        'it' => [
            'termini', 'termini-e-condizioni', 'condizioni-uso',
            'note-legali',
        ],
    ],

    'blog_page' => [
        // English
        'en' => [
            'blog', 'news', 'articles', 'insights', 'updates',
        ],
        // Dutch
        'nl' => [
            'blog', 'nieuws', 'artikelen', 'inzichten', 'updates',
            'nieuwsberichten', 'actueel',
        ],
        // German
        'de' => [
            'blog', 'nachrichten', 'neuigkeiten', 'artikel', 'aktuelles',
        ],
        // French
        'fr' => [
            'blog', 'actualites', 'nouvelles', 'articles', 'mises-a-jour',
        ],
        // Spanish
        'es' => [
            'blog', 'noticias', 'articulos', 'novedades', 'actualizaciones',
        ],
        // Italian
        'it' => [
            'blog', 'notizie', 'articoli', 'novita', 'aggiornamenti',
        ],
    ],

    'careers_page' => [
        // English
        'en' => [
            'careers', 'jobs', 'work-with-us', 'join-us', 'opportunities',
            'employment', 'vacancies',
        ],
        // Dutch
        'nl' => [
            'carriere', 'vacatures', 'werken-bij', 'werk-bij-ons',
            'mogelijkheden', 'banen', 'jobs', 'werkgelegenheid',
        ],
        // German
        'de' => [
            'karriere', 'jobs', 'stellenangebote', 'arbeiten-bei-uns',
            'moglichkeiten',
        ],
        // French
        'fr' => [
            'carrieres', 'emplois', 'travailler-avec-nous', 'rejoignez-nous',
            'opportunites', 'recrutement',
        ],
        // Spanish
        'es' => [
            'carreras', 'empleos', 'trabaja-con-nosotros', 'unete',
            'oportunidades', 'vacantes',
        ],
        // Italian
        'it' => [
            'carriere', 'lavoro', 'lavora-con-noi', 'opportunita',
            'posizioni-aperte',
        ],
    ],

    'faq_page' => [
        // English
        'en' => [
            'faq', 'faqs', 'frequently-asked-questions', 'help', 'support',
            'questions',
        ],
        // Dutch
        'nl' => [
            'faq', 'veelgestelde-vragen', 'vragen', 'hulp', 'support',
            'ondersteuning', 'veel-gestelde-vragen',
        ],
        // German
        'de' => [
            'faq', 'haufig-gestellte-fragen', 'hilfe', 'support',
            'unterstutzung',
        ],
        // French
        'fr' => [
            'faq', 'questions-frequentes', 'aide', 'support',
            'assistance',
        ],
        // Spanish
        'es' => [
            'faq', 'preguntas-frecuentes', 'ayuda', 'soporte',
            'asistencia',
        ],
        // Italian
        'it' => [
            'faq', 'domande-frequenti', 'aiuto', 'supporto',
            'assistenza',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority Scores
    |--------------------------------------------------------------------------
    |
    | Priority scores for different page types when extracting contacts.
    | Higher scores indicate more reliable contact sources.
    |
    */

    'priority_scores' => [
        'contact_page' => 30,
        'about_page' => 20,
        'team_page' => 25,
        'services_page' => 15,
        'careers_page' => 18,
        'blog_page' => 10,
        'faq_page' => 12,
        'privacy_page' => 5,
        'terms_page' => 5,
        'header' => 15,
        'footer' => 10,
        'body' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Language
    |--------------------------------------------------------------------------
    |
    | The default language to use when no specific language is detected.
    |
    */

    'default_language' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Enabled Languages
    |--------------------------------------------------------------------------
    |
    | Languages to check when matching URL patterns. Add or remove languages
    | as needed for your use case.
    |
    */

    'enabled_languages' => ['en', 'nl', 'de', 'fr', 'es', 'it'],

];
