import LegalLayout, { BulletList, Section, type LegalLinks, type LegalMetadata } from './LegalLayout';

type Props = {
    legal: LegalMetadata;
    legalLinks: LegalLinks;
};

export default function Privacy({ legal, legalLinks }: Props) {
    const contact = legal.contactEmail
        ? <a className="text-primary underline-offset-4 hover:underline" href={`mailto:${legal.contactEmail}`}>{legal.contactEmail}</a>
        : 'adresse de contact à compléter avant la mise en production';

    return (
        <LegalLayout
            title="Politique de confidentialité"
            description="Cette politique détaille les données que 10xScale ERP collecte, consulte, stocke et utilise pour fournir le service ERP, sécuriser les comptes et permettre les intégrations Google choisies par les utilisateurs autorisés."
            legal={legal}
            legalLinks={legalLinks}
        >
            <Section title="1. À propos de 10xScale ERP">
                <p>
                    10xScale ERP est un produit SaaS multi-tenant exploité sous la marque 10xScale et accessible notamment sur le domaine 10xscale.agency.
                    La société n’étant pas encore légalement incorporée, cette page n’invente pas de raison sociale, d’adresse physique, de numéro RC, ICE ou autre immatriculation.
                </p>
                <p>
                    Le service aide les organisations à gérer leurs opérations commerciales : utilisateurs, rôles, magasins, clients, fournisseurs, produits,
                    stocks, devis, commandes, point de vente, factures, bons de livraison, paiements, retours, avoirs, rapports financiers, paramètres,
                    e-mails d’organisation et sauvegardes.
                </p>
                <p>
                    Chaque organisation saisit et gère ses propres données métier. 10xScale ERP ne revendique pas la propriété des données commerciales
                    appartenant aux organisations ou à leurs clients, fournisseurs et utilisateurs.
                </p>
            </Section>

            <Section title="2. Données que nous traitons">
                <p>Selon les fonctions utilisées, 10xScale ERP peut traiter les catégories suivantes :</p>
                <BulletList>
                    <li><strong className="text-ink">Informations de compte :</strong> nom, adresse e-mail, identifiants de compte, mot de passe haché lorsqu’un mot de passe ERP est défini, état de vérification e-mail, préférences de sécurité, double authentification et codes de récupération hachés.</li>
                    <li><strong className="text-ink">Informations d’organisation :</strong> organisation, magasins, utilisateurs, invitations, memberships, rôles, permissions, paramètres de documents, logos, cachets, configuration SMTP et configuration de sauvegarde.</li>
                    <li><strong className="text-ink">Données opérationnelles ERP :</strong> clients, fournisseurs, produits, variantes, catégories, marques, inventaire, mouvements de stock, transferts, devis, commandes, factures, bons de livraison, paiements, remboursements, retours, avoirs, échanges et données de reporting financier.</li>
                    <li><strong className="text-ink">Fichiers et documents :</strong> logos, cachets, PDF commerciaux, fichiers importés, archives de sauvegarde <span className="font-mono text-xs text-ink">.erpbackup</span> et documents nécessaires aux fonctionnalités activées.</li>
                    <li><strong className="text-ink">Données techniques et de sécurité :</strong> sessions, cookies de session, adresse IP, agent navigateur/appareil, journaux d’audit, événements d’authentification et traces d’opérations sensibles.</li>
                </BulletList>
            </Section>

            <Section title="3. Utilisation des données">
                <p>Nous utilisons ces données uniquement pour fournir, maintenir et sécuriser 10xScale ERP, notamment pour :</p>
                <BulletList>
                    <li>créer, vérifier et protéger les comptes utilisateurs ;</li>
                    <li>authentifier les utilisateurs par mot de passe ERP, double authentification ou Google lorsque choisi ;</li>
                    <li>appliquer l’isolation par organisation, les rôles, permissions et accès magasin ;</li>
                    <li>fournir les modules ERP : catalogue, stock, POS, ventes, documents, paiements, retours, finance, e-mail et sauvegardes ;</li>
                    <li>générer, envoyer ou afficher des documents commerciaux lorsque l’organisation utilise ces fonctions ;</li>
                    <li>créer, valider, restaurer ou synchroniser des sauvegardes d’organisation ;</li>
                    <li>prévenir les abus, diagnostiquer les erreurs, produire des journaux d’audit et sécuriser le service ;</li>
                    <li>répondre aux demandes de support, de sécurité ou de suppression de données.</li>
                </BulletList>
            </Section>

            <Section title="4. Connexion avec Google">
                <p>
                    Les utilisateurs peuvent choisir de se connecter avec Google. Cette connexion est optionnelle : un compte peut aussi utiliser une adresse e-mail
                    et un mot de passe ERP lorsque le mot de passe a été défini.
                </p>
                <p>
                    Pour la connexion Google, 10xScale ERP demande uniquement les portées d’identité actuellement utilisées :
                    <span className="font-mono text-xs text-ink"> openid</span>,
                    <span className="font-mono text-xs text-ink"> email</span> et
                    <span className="font-mono text-xs text-ink"> profile</span>.
                </p>
                <p>Google peut alors fournir à 10xScale ERP :</p>
                <BulletList>
                    <li>un identifiant stable du compte Google ;</li>
                    <li>l’adresse e-mail Google et son état de vérification ;</li>
                    <li>des informations de profil de base, par exemple le nom et l’avatar si fournis par Google.</li>
                </BulletList>
                <p>Ces données sont utilisées pour authentifier l’utilisateur, créer ou lier le compte 10xScale ERP correspondant, identifier l’utilisateur dans le service et sécuriser l’accès au compte.</p>
                <p>
                    La connexion avec Google ne donne pas accès à Gmail, Google Contacts, Google Calendar ou Google Drive. La sauvegarde Google Drive est une autorisation séparée.
                </p>
            </Section>

            <Section title="5. Sauvegardes Google Drive">
                <p>
                    Un utilisateur autorisé d’une organisation peut choisir séparément de connecter Google Drive pour synchroniser des copies de sauvegarde.
                    Cette autorisation est optionnelle et n’est pas accordée automatiquement par la connexion Google.
                </p>
                <p>
                    L’intégration Google Drive utilise la portée :
                    <span className="font-mono text-xs text-ink"> https://www.googleapis.com/auth/drive.file</span>.
                    Cette portée est destinée aux fichiers et dossiers créés ou utilisés par l’application dans le cadre de l’autorisation accordée.
                </p>
                <p>Avec cette autorisation, 10xScale ERP peut :</p>
                <BulletList>
                    <li>créer ou réutiliser un dossier d’application « 10xScale ERP / Sauvegardes » ;</li>
                    <li>envoyer des archives de sauvegarde d’organisation au format <span className="font-mono text-xs text-ink">.erpbackup</span> ;</li>
                    <li>enregistrer l’identifiant du fichier Google Drive correspondant à une copie de sauvegarde ;</li>
                    <li>tester l’accès au dossier de sauvegarde ;</li>
                    <li>télécharger une copie Google Drive privée afin de la valider avant restauration ;</li>
                    <li>restaurer une organisation uniquement après validation de la sauvegarde et confirmation explicite.</li>
                </BulletList>
                <p>
                    10xScale ERP ne demande pas l’accès complet à tout le Google Drive de l’utilisateur et ne revendique pas lire les fichiers personnels
                    qui ne sont pas créés ou autorisés pour cette intégration.
                </p>
            </Section>

            <Section title="6. Utilisation des données Google">
                <p><strong className="text-ink">Données Google consultées pour la connexion :</strong> identifiant Google stable, e-mail, état de vérification de l’e-mail et profil de base lorsque fourni.</p>
                <p><strong className="text-ink">Pourquoi :</strong> authentifier l’utilisateur, créer ou relier le compte 10xScale ERP, éviter les doublons de compte et sécuriser l’accès.</p>
                <p><strong className="text-ink">Données Google Drive utilisées :</strong> jetons d’accès/rafraîchissement OAuth, portée autorisée, identifiants de dossier/fichier créés pour les sauvegardes, statut de synchronisation et métadonnées techniques nécessaires au transfert.</p>
                <p><strong className="text-ink">Pourquoi :</strong> créer le dossier de sauvegarde, envoyer les archives, vérifier les copies, télécharger une copie pour validation/restauration et renouveler l’accès lorsque Google l’autorise.</p>
                <p>
                    Les jetons OAuth Google Drive sont stockés chiffrés au repos dans la base de données. Les jetons, secrets OAuth et mots de passe ne sont pas affichés dans l’interface publique.
                </p>
                <p>
                    Les données obtenues via les API Google ne sont pas vendues, ne sont pas utilisées pour de la publicité ciblée, ne sont pas fournies à des courtiers de données,
                    ne sont pas utilisées pour déterminer la solvabilité et ne sont pas utilisées pour entraîner des modèles d’IA ou de machine learning généralisés/non personnalisés.
                </p>
            </Section>

            <Section title="7. Partage et divulgation">
                <p>
                    10xScale ERP ne vend pas les données utilisateur, les données métier d’organisation ou les données Google. Nous ne les utilisons pas pour de la publicité ciblée.
                </p>
                <p>Les données peuvent être traitées ou divulguées uniquement dans des cas limités :</p>
                <BulletList>
                    <li>aux utilisateurs autorisés de la même organisation, selon leurs rôles, permissions et accès magasin ;</li>
                    <li>aux prestataires techniques nécessaires au fonctionnement du service, par exemple hébergement, base de données, stockage, e-mail ou infrastructure ;</li>
                    <li>à Google lorsque l’utilisateur utilise Google Auth ou connecte Google Drive ;</li>
                    <li>aux serveurs SMTP configurés par une organisation pour envoyer ses e-mails commerciaux ;</li>
                    <li>lorsque cela est nécessaire pour la sécurité, le diagnostic, le support ou le respect d’obligations applicables.</li>
                </BulletList>
                <p>
                    Lorsque l’identité exacte d’un prestataire dépend du déploiement ou de la configuration, cette politique utilise des catégories de prestataires plutôt que d’inventer des noms.
                </p>
            </Section>

            <Section title="8. Conservation des données">
                <p>
                    Les données de compte, d’organisation et métier sont généralement conservées tant que le compte ou l’organisation utilise le service, et aussi longtemps
                    que cela est raisonnablement nécessaire pour fournir le service, préserver la sécurité, maintenir les journaux d’audit, permettre la restauration,
                    traiter les demandes de support ou respecter des obligations applicables.
                </p>
                <p>
                    Les sessions expirent selon la configuration du service. Les jetons de réinitialisation ou d’accès temporaires sont conservés pour la durée nécessaire à leur usage.
                    Les journaux d’audit et de sécurité peuvent être conservés plus longtemps afin de protéger les organisations et diagnostiquer les incidents.
                </p>
                <p>
                    Les sauvegardes d’organisation peuvent contenir une copie des données métier. Les sauvegardes programmées disposent d’un paramètre de rétention configurable
                    par organisation, borné actuellement entre 7 et 90 sauvegardes réussies conservées. Les sauvegardes supprimées par rétention peuvent laisser une trace d’audit
                    indiquant qu’une suppression de rétention a eu lieu.
                </p>
                <p>
                    Une copie envoyée vers Google Drive reste dans le Google Drive connecté tant que l’utilisateur ou l’organisation ne la supprime pas depuis Google Drive,
                    même si la connexion est ensuite désactivée dans 10xScale ERP.
                </p>
            </Section>

            <Section title="9. Sécurité">
                <p>
                    Le service comprend des contrôles factuels tels que l’authentification, le hachage des mots de passe, l’autorisation par rôles et permissions,
                    l’isolation par organisation, la double authentification optionnelle, les journaux d’audit, la validation de sauvegarde, la protection CSRF
                    et le stockage chiffré de certains secrets et jetons OAuth.
                </p>
                <p>
                    Les accès aux sauvegardes, paramètres SMTP, documents et données métier sont soumis aux permissions et au contexte d’organisation actifs.
                    Aucune mesure de sécurité ne peut garantir une protection parfaite ; nous ne revendiquons pas de certification SOC 2, ISO 27001, « militaire »
                    ou « 100 % sécurisé ».
                </p>
            </Section>

            <Section title="10. Cookies et sessions">
                <p>
                    10xScale ERP utilise des cookies et données de session pour maintenir la connexion, protéger les requêtes, appliquer les contrôles de sécurité
                    et permettre le fonctionnement normal de l’application. La configuration actuelle utilise des sessions serveur, un cookie de session HTTP-only
                    et une politique SameSite adaptée aux protections CSRF.
                </p>
                <p>
                    Aucune utilisation de cookies publicitaires ou d’analytics tiers n’a été identifiée dans le code inspecté pour cette politique.
                </p>
            </Section>

            <Section title="11. Vos choix et contrôle de vos données">
                <BulletList>
                    <li>Vous pouvez utiliser la connexion e-mail/mot de passe ERP si un mot de passe est configuré, ou la connexion Google si vous la choisissez.</li>
                    <li>Les administrateurs autorisés peuvent gérer les utilisateurs, rôles, magasins, paramètres et certaines données de leur organisation.</li>
                    <li>Les organisations peuvent exporter ou générer certains documents et sauvegardes selon les fonctions disponibles.</li>
                    <li>Les organisations peuvent supprimer certaines entités comme des magasins ou organisations lorsque les permissions et règles applicatives le permettent.</li>
                    <li>Si une suppression complète de compte, de données ou d’organisation n’est pas disponible automatiquement dans l’interface, vous devez contacter l’opérateur du service : {contact}.</li>
                </BulletList>
                <p>
                    Certaines demandes de suppression ou correction peuvent être limitées par les besoins de sécurité, d’audit, de sauvegarde, d’intégrité comptable,
                    de conservation technique ou d’obligations applicables.
                </p>
            </Section>

            <Section title="12. Révocation de l’accès Google">
                <p>
                    Pour la connexion Google, vous pouvez cesser d’utiliser Google comme méthode de connexion et définir/utiliser un mot de passe ERP lorsque cette option est configurée.
                    Les informations de liaison Google nécessaires à l’identification du compte peuvent rester associées au compte tant qu’elles sont nécessaires au service ou jusqu’à traitement d’une demande applicable.
                </p>
                <p>
                    Pour Google Drive, un utilisateur autorisé peut déconnecter l’intégration depuis les paramètres de sauvegarde de l’organisation. La déconnexion locale efface les jetons
                    d’accès et de rafraîchissement stockés, désactive la synchronisation et marque l’état comme déconnecté. 10xScale ERP tente également de révoquer le jeton auprès de Google lorsque possible.
                </p>
                <p>
                    Vous pouvez aussi révoquer l’accès depuis votre compte Google. Les fichiers de sauvegarde déjà présents dans Google Drive ne sont pas supprimés automatiquement
                    par la déconnexion locale ; ils doivent être supprimés dans Google Drive si vous ne souhaitez plus les conserver.
                </p>
            </Section>

            <Section title="13. Modifications de cette politique">
                <p>
                    Cette politique peut être mise à jour pour refléter l’évolution du service, des intégrations, des exigences Google ou des obligations applicables.
                    La date de dernière mise à jour figure en haut de cette page.
                </p>
            </Section>

            <Section title="14. Nous contacter">
                <p>
                    Pour toute question relative à cette politique, à l’utilisation des données, à Google Auth, Google Drive, ou pour demander l’accès, la correction
                    ou la suppression de données, contactez : {contact}.
                </p>
                {!legal.contactEmail && (
                    <p className="rounded-card border border-warning/30 bg-warning-soft px-4 py-3 text-warning">
                        Information propriétaire requise avant production : adresse e-mail de contact confidentialité/support opérationnelle.
                    </p>
                )}
            </Section>
        </LegalLayout>
    );
}
