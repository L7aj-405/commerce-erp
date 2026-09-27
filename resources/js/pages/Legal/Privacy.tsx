import LegalLayout, { BulletList, Section, type LegalLinks, type LegalMetadata } from './LegalLayout';

type Props = {
    legal: LegalMetadata;
    legalLinks: LegalLinks;
};

export default function Privacy({ legal, legalLinks }: Props) {
    return (
        <LegalLayout
            title="Politique de confidentialité"
            description="Cette politique explique quelles informations 10xScale ERP traite pour fournir le service ERP SaaS, sécuriser les comptes et permettre les intégrations choisies par les organisations."
            legal={legal}
            legalLinks={legalLinks}
        >
            <Section title="1. Champ d’application">
                <p>
                    {legal.productName} est une application ERP SaaS multi-tenant utilisée par des organisations pour gérer leurs opérations commerciales :
                    utilisateurs, rôles, magasins, clients, fournisseurs, catalogue, stock, ventes, point de vente, devis, factures, bons de livraison,
                    paiements, retours, avoirs, reporting financier, paramètres, e-mail/SMTP et sauvegardes.
                </p>
                <p>
                    Les organisations restent responsables des informations professionnelles qu’elles saisissent dans leur espace. {legal.productName} les traite
                    pour fournir, sécuriser et maintenir le service.
                </p>
            </Section>

            <Section title="2. Informations traitées">
                <BulletList>
                    <li>Informations de compte : nom, adresse e-mail, mot de passe haché lorsque l’utilisateur en définit un, état de vérification, préférences de sécurité et double authentification.</li>
                    <li>Informations d’organisation : sociétés, membres, rôles, permissions, magasins, paramètres de documents, paramètres SMTP, cachet/logo et paramètres de sauvegarde.</li>
                    <li>Données métier saisies dans l’ERP : clients, fournisseurs, produits, variantes, catégories, marques, stocks, mouvements, commandes, devis, factures, bons de livraison, paiements, retours, avoirs, échanges et rapports.</li>
                    <li>Données techniques et de sécurité : sessions, adresses IP, agents utilisateurs, journaux d’audit, événements de connexion, changements sensibles et traces d’opérations métier.</li>
                    <li>Fichiers et ressources : logos, cachets, PDF de documents commerciaux, archives de sauvegarde privées et fichiers importés selon les fonctions utilisées.</li>
                </BulletList>
            </Section>

            <Section title="3. Finalités du traitement">
                <BulletList>
                    <li>Créer et gérer les comptes utilisateurs, l’authentification et l’accès aux organisations.</li>
                    <li>Fournir les fonctions ERP : catalogue, stock, vente, documents, paiements, retours, finance, sauvegardes et restauration.</li>
                    <li>Appliquer l’isolation par organisation, les rôles, les permissions et les contrôles de sécurité.</li>
                    <li>Produire des journaux d’audit et aider au diagnostic d’incidents, d’erreurs ou d’abus.</li>
                    <li>Envoyer des e-mails applicatifs ou commerciaux lorsque la configuration le permet.</li>
                    <li>Respecter les obligations de sécurité, de maintenance et de support applicables au service.</li>
                </BulletList>
            </Section>

            <Section title="4. Authentification Google">
                <p>
                    Les utilisateurs peuvent choisir de se connecter avec Google. Dans ce cas, {legal.productName} demande les informations d’identité nécessaires
                    à l’authentification, avec les portées Google <span className="font-mono text-xs text-ink">openid</span>, <span className="font-mono text-xs text-ink">email</span> et <span className="font-mono text-xs text-ink">profile</span>.
                    Ces informations servent à vérifier l’identité, créer ou lier le compte ERP et faciliter la connexion.
                </p>
                <p>
                    La connexion avec Google ne donne pas automatiquement accès à Google Drive. L’autorisation Google Drive est une intégration distincte,
                    optionnelle et déclenchée séparément par un utilisateur autorisé de l’organisation.
                </p>
            </Section>

            <Section title="5. Google Drive pour les sauvegardes">
                <p>
                    Un utilisateur autorisé peut connecter Google Drive pour synchroniser des copies de sauvegarde d’organisation. L’intégration utilise la portée
                    <span className="font-mono text-xs text-ink"> https://www.googleapis.com/auth/drive.file</span>, destinée aux fichiers et dossiers créés ou utilisés par l’application dans le cadre de l’autorisation accordée.
                </p>
                <BulletList>
                    <li>Le service peut créer ou réutiliser un dossier d’application « 10xScale ERP / Sauvegardes ».</li>
                    <li>Il peut envoyer des archives de sauvegarde, vérifier une copie, télécharger une copie pour validation/restauration et tester l’accès au dossier.</li>
                    <li>Les jetons OAuth Google Drive sont stockés chiffrés au repos dans l’application.</li>
                    <li>La déconnexion locale efface les jetons stockés et désactive la synchronisation. Les fichiers déjà présents dans le Google Drive de l’utilisateur ne sont pas supprimés automatiquement.</li>
                    <li>L’utilisateur peut aussi révoquer l’accès depuis son compte Google.</li>
                </BulletList>
                <p>
                    {legal.productName} n’utilise pas les données Google à des fins publicitaires et ne les vend pas.
                </p>
            </Section>

            <Section title="6. E-mails et SMTP d’organisation">
                <p>
                    Les organisations peuvent configurer leur propre serveur SMTP pour envoyer des documents commerciaux, par exemple des factures, devis ou bons de livraison.
                    Les identifiants SMTP configurés sont utilisés uniquement pour l’envoi des e-mails de l’organisation concernée et ne remplacent pas la configuration e-mail globale de la plateforme.
                </p>
            </Section>

            <Section title="7. Prestataires et services tiers">
                <p>
                    Le service peut s’appuyer sur des prestataires d’hébergement, de stockage, de messagerie, de base de données et sur les services Google lorsque
                    l’utilisateur choisit Google Auth ou Google Drive. Lorsque le prestataire exact n’est pas affiché dans l’application, cette politique utilise
                    des catégories générales plutôt que d’inventer des noms de fournisseurs.
                </p>
            </Section>

            <Section title="8. Conservation et sauvegardes">
                <p>
                    Les données sont conservées tant qu’elles sont nécessaires au fonctionnement du compte, de l’organisation, des obligations de sécurité ou de support,
                    sauf suppression ou obligation contraire. L’application dispose de sauvegardes d’organisation privées et peut synchroniser des copies vers Google Drive
                    si l’organisation l’active.
                </p>
                <p>
                    Les sauvegardes améliorent la capacité de restauration mais ne constituent pas une garantie absolue contre toute perte de données, interruption ou incident.
                </p>
            </Section>

            <Section title="9. Sécurité">
                <p>
                    Le service inclut notamment l’authentification, le hachage des mots de passe, l’autorisation par rôles et permissions, l’isolation par organisation,
                    la double authentification optionnelle, des journaux d’audit, le chiffrement de certains secrets et jetons, ainsi que des validations de sauvegarde.
                    Aucune mesure de sécurité ne peut garantir une protection parfaite.
                </p>
            </Section>

            <Section title="10. Vos demandes">
                <p>
                    Pour toute demande relative à un compte, à une organisation, à l’accès, la correction ou la suppression de données, contactez l’opérateur du service :
                    {' '}{legal.contactEmail ? <a className="text-primary underline-offset-4 hover:underline" href={`mailto:${legal.contactEmail}`}>{legal.contactEmail}</a> : 'adresse de contact à compléter avant la mise en production'}.
                    Certaines suppressions peuvent être limitées par les besoins de sécurité, d’audit, de sauvegarde, de facturation ou d’obligations légales applicables.
                </p>
            </Section>

            <Section title="11. Modifications">
                <p>
                    Cette politique peut être mise à jour pour refléter l’évolution du service, des intégrations ou des obligations applicables. La date de dernière mise à jour figure en haut de cette page.
                </p>
            </Section>
        </LegalLayout>
    );
}
