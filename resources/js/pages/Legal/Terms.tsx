import LegalLayout, { BulletList, Section, type LegalLinks, type LegalMetadata } from './LegalLayout';

type Props = {
    legal: LegalMetadata;
    legalLinks: LegalLinks;
};

export default function Terms({ legal, legalLinks }: Props) {
    return (
        <LegalLayout
            title="Conditions d'utilisation"
            description="Ces conditions encadrent l’utilisation de 10xScale ERP par les organisations et leurs utilisateurs autorisés."
            legal={legal}
            legalLinks={legalLinks}
        >
            <Section title="1. Acceptation">
                <p>
                    En créant un compte, en vous connectant ou en utilisant {legal.productName}, vous acceptez ces conditions pour vous-même et, le cas échéant,
                    pour l’organisation que vous représentez. Si vous n’êtes pas autorisé à engager cette organisation, vous ne devez pas utiliser son espace.
                </p>
            </Section>

            <Section title="2. Description du service">
                <p>
                    {legal.productName} est un ERP SaaS multi-tenant pour gérer des opérations commerciales : organisations, utilisateurs, magasins, catalogue,
                    inventaire, commandes, POS, devis, factures, bons de livraison, paiements, retours, avoirs, rapports, paramètres, e-mails d’organisation et sauvegardes.
                </p>
            </Section>

            <Section title="3. Comptes, organisations et sécurité">
                <BulletList>
                    <li>Chaque utilisateur doit fournir des informations exactes et garder ses identifiants confidentiels.</li>
                    <li>Les administrateurs d’organisation sont responsables des invitations, rôles, permissions, accès magasin et configurations qu’ils accordent.</li>
                    <li>Le service prend en charge la connexion e-mail/mot de passe, la connexion Google et la double authentification ERP.</li>
                    <li>Vous devez signaler rapidement tout accès non autorisé ou incident de sécurité suspecté.</li>
                </BulletList>
            </Section>

            <Section title="4. Données métier">
                <p>
                    L’organisation conserve la responsabilité des données qu’elle saisit dans l’ERP : clients, fournisseurs, employés/utilisateurs, produits, prix,
                    stocks, transactions, documents, paiements, retours, fichiers et paramètres. Vous devez disposer des droits nécessaires pour saisir, importer
                    ou traiter ces informations dans le service.
                </p>
            </Section>

            <Section title="5. Utilisation acceptable">
                <p>Vous ne devez pas utiliser le service pour :</p>
                <BulletList>
                    <li>tenter de contourner l’authentification, les permissions, l’isolation tenant ou les limites techniques ;</li>
                    <li>accéder aux données d’une autre organisation ;</li>
                    <li>introduire du contenu illégal, malveillant, frauduleux ou portant atteinte aux droits de tiers ;</li>
                    <li>perturber le service, ses sauvegardes, ses intégrations ou son infrastructure ;</li>
                    <li>utiliser les intégrations Google ou e-mail d’une manière contraire aux règles applicables de ces services.</li>
                </BulletList>
            </Section>

            <Section title="6. Google Auth et Google Drive">
                <p>
                    La connexion Google est utilisée pour authentifier ou lier un compte ERP avec les portées d’identité <span className="font-mono text-xs text-ink">openid</span>,
                    <span className="font-mono text-xs text-ink"> email</span> et <span className="font-mono text-xs text-ink"> profile</span>.
                    Elle est distincte de Google Drive.
                </p>
                <p>
                    Google Drive peut être connecté séparément par un utilisateur autorisé afin de synchroniser des copies de sauvegarde d’organisation avec la portée
                    <span className="font-mono text-xs text-ink"> https://www.googleapis.com/auth/drive.file</span>. La révocation ou la déconnexion peut empêcher les futures synchronisations.
                </p>
            </Section>

            <Section title="7. Sauvegardes et restauration">
                <p>
                    Le service peut créer des sauvegardes privées d’organisation et, si l’option est connectée, synchroniser une copie vers Google Drive. Les sauvegardes
                    sont une mesure de résilience, pas une garantie de disponibilité permanente, de restauration parfaite ou d’absence totale de perte de données.
                    L’organisation doit également conserver les contrôles et procédures qu’elle juge nécessaires pour son activité.
                </p>
            </Section>

            <Section title="8. Services tiers">
                <p>
                    Certaines fonctions dépendent de services tiers, notamment Google pour l’authentification ou Drive, les serveurs SMTP configurés par l’organisation,
                    l’hébergement, le stockage et la messagerie de la plateforme. Ces services peuvent appliquer leurs propres conditions et limites.
                </p>
            </Section>

            <Section title="9. Disponibilité, maintenance et évolutions">
                <p>
                    Le service peut évoluer, être corrigé ou faire l’objet de maintenances. Sauf engagement contractuel séparé, aucune disponibilité ininterrompue,
                    aucun niveau de service spécifique et aucune récupération garantie ne sont promis par ces conditions.
                </p>
            </Section>

            <Section title="10. Propriété intellectuelle">
                <p>
                    Le logiciel, l’interface, les marques, le code et les éléments fournis par l’éditeur restent la propriété de leurs titulaires. Les données métier
                    saisies par une organisation restent les données de cette organisation ou de ses ayants droit.
                </p>
            </Section>

            <Section title="11. Suspension et clôture">
                <p>
                    L’accès peut être suspendu ou limité en cas de risque de sécurité, d’utilisation abusive, de violation de ces conditions ou d’obligation applicable.
                    Les conséquences précises d’une clôture de compte ou d’organisation doivent être convenues avec l’opérateur du service, notamment pour l’export,
                    la conservation, les sauvegardes et les obligations légales.
                </p>
            </Section>

            <Section title="12. Facturation">
                <p>
                    Le code inspecté ne montre pas de module de souscription, de tarification ou de remboursement intégré. Si une offre commerciale, un abonnement,
                    une facturation ou une politique de remboursement s’applique, ces éléments doivent être fournis séparément par l’opérateur avant publication.
                </p>
            </Section>

            <Section title="13. Limitation et avertissements">
                <p>
                    Dans la mesure permise par le droit applicable, le service est fourni sans garantie absolue d’absence d’erreur, d’interruption, de perte ou de faille.
                    Les décisions comptables, fiscales, commerciales ou juridiques prises à partir des données de l’ERP restent sous la responsabilité de l’organisation.
                </p>
            </Section>

            <Section title="14. Droit applicable, juridiction et contact">
                <p>
                    Le droit applicable, la juridiction compétente, l’identité légale complète de l’opérateur et l’adresse de contact officielle ne sont pas présents
                    dans le code inspecté. Ces informations doivent être complétées par le propriétaire avant la mise en production.
                </p>
                <p>
                    Contact : {legal.contactEmail ? <a className="text-primary underline-offset-4 hover:underline" href={`mailto:${legal.contactEmail}`}>{legal.contactEmail}</a> : 'adresse de contact à compléter avant la mise en production'}.
                </p>
            </Section>

            <Section title="15. Modifications des conditions">
                <p>
                    Ces conditions peuvent être mises à jour pour refléter les changements du service ou du cadre applicable. La date de dernière mise à jour figure en haut de cette page.
                </p>
            </Section>
        </LegalLayout>
    );
}
