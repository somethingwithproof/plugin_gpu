<!-- Source: README.md; SHA256: 126deef1783d64f878bf565f1433c10f31aef828eb9bc313aeff2b0335e54c7c -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Un plugin Cacti 1.2 qui intègre dans Cacti l'utilisation, la mémoire, la température, la puissance, les erreurs ECC et les raisons de limitation (*throttling*) par GPU à partir des outils NVIDIA. La prise en charge d'AMD et la collecte des erreurs XID sont prévues, mais ne sont pas encore implémentées. Chaque GPU devient un index d'une requête de données sur l'équipement de son nœud ; ainsi, les graphiques GPU apparaissent aux côtés des autres graphiques du nœud selon les permissions de Cacti, et Thold et Maint peuvent déclencher des alertes et les suspendre.

> **Statut : en cours de développement, non publié.** Le jalon 4 rend le code de la phase 1 (NVIDIA via SNMP extend) complet : collecte, identité, graphiques, signaux de limitation et d'erreurs ECC, alertes Thold et Maint, page d'état de santé et documentation opérateur. Il n'a été exécuté que face à un véritable `snmpd` avec un faux `nvidia-smi` affichant des sorties synthétiques, jamais sur du matériel GPU réel. Points encore ouverts avant une publication : pilotes sur matériel réel ([docs/pilot-plan.md](../../pilot-plan.md), #54), audit de sécurité externe ([docs/security-review.md](../../security-review.md)), jeux de données capturés depuis de vrais outils (#14) et mesures de montée en charge sur du matériel de taille production ([docs/performance.md](../../performance.md)). Les erreurs XID attendent la décision #8. Ne l'utilisez pas en production.

## Rôles d'accès

| Domaine | Accès |
| --- | --- |
| GPU View | Pages de supervision existantes. |
| GPU Operator | Supervision, plus la page Opérations et les acquittements de triage personnels. |
| GPU Admin | Accès Opérateur, plus la configuration existante et les actions d'administration. |

Après avoir copié les fichiers de mise à niveau, ouvrez la page Plugins en tant qu'administrateur pour enregistrer le nouveau domaine. Attribuez explicitement le domaine Operator sous **Configuration > Utilisateurs** ou dans les groupes d'utilisateurs. Les mises à niveau conservent les identifiants et les autorisations des domaines View et Admin existants. L'accès Admin hérite de l'accès Operator, et l'accès Operator hérite de l'accès View à chaque requête ; cela n'ajoute pas d'autorisations stockées aux utilisateurs ni aux groupes. Les paramètres du plugin nécessitent également l'accès GPU Admin en plus de la permission Settings de Cacti.

Les permissions d'équipement de Cacti continuent de limiter les lignes et les actions disponibles pour chaque utilisateur ; les objets non mappés nécessitent un administrateur. Les identifiants, la configuration, le mappage, la suppression et les autres modifications d'administration restent dans les pages d'administration. Les acquittements ne durent que pendant la session en cours de l'utilisateur, pour une heure au maximum et uniquement tant que la même observation reste fraîche. Toute observation nouvelle ou modifiée les invalide. Ils ne marquent jamais une mesure en échec comme saine et ne suppriment jamais les alertes.

## Pourquoi

Les nœuds GPU sont généralement déjà présents dans Cacti pour le CPU, la mémoire et les interfaces. L'état de santé des GPU est une question distincte à laquelle les données SNMP du nœud ne répondent pas : une carte peut subir une limitation thermique, accumuler des erreurs ECC ou signaler une erreur XID alors que le nœud lui-même semble sain. Les outils des constructeurs le signalent (`nvidia-smi`, DCGM, `amd-smi`), mais leurs sorties restent en dehors des graphiques, des permissions et des alertes de Cacti.

Le plugin comble cette lacune en transformant la sortie des outils constructeurs en sources de données Cacti, indexées selon l'identité de chaque GPU plutôt que sa position, de sorte qu'un redémarrage réordonnant les GPU ne rattache pas l'historique d'une carte à une autre. Il réutilise la génération de graphiques, les permissions, les collecteurs de données distants, Thold et Maint de Cacti plutôt que d'en reconstruire des versions parallèles.

## Périmètre prévu

| Phase | Périmètre | Jalons |
|---|---|---|
| 0, recherche | Sortie des outils constructeurs, signaux XID et de limitation, identité des GPU, privilèges, décision sur la méthode de collecte | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Collecte `nvidia-smi`, inventaire par UUID, graphiques, signaux de limitation, ECC et XID, Thold et Maint, page d'état de santé | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backends | Backend DCGM, backend `amd-smi` | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig et distant | Instances MIG, collecteurs de données distants Cacti | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Hors périmètre : toute commande modifiant un GPU ou un nœud (fréquences, limites de puissance, réinitialisations, configuration MIG), un moteur d'alerte propre au plugin, un démon ou protocole réseau personnalisé, ainsi que la comptabilisation de l'ordonnanceur ou des tâches. Consultez le plan pour la liste des travaux de recherche en attente.

## Apports du jalon 4

- **Raisons de limitation comme valeurs d'alerte.** Le masque d'événements d'horloge est décodé en un indicateur à 0 ou 1 par raison ; un masque inconnu donne des indicateurs inconnus, jamais des zéros, et les bits sans indicateur restent dans le masque brut, affiché par GPU Health.
- **ECC comme valeurs d'alerte** ([docs/metrics.md](../../metrics.md)). Les nouvelles erreurs corrigées et non corrigées depuis l'échantillon précédent sont calculées par le plugin à partir de deux totaux consécutifs et stockées sous forme de jauges (*gauges*) : inconnues lors d'un premier échantillon, d'une lecture manquée ou d'un rechargement du pilote, afin qu'une réinitialisation ne soit jamais lue comme zéro ni comme un compteur négatif. Les nombres de pages retirées pour erreur simple bit et double bit ainsi que le mode ECC sont également lus. Deux graphiques ECC supplémentaires par GPU permettent à Cacti de les stocker ; avec Power Percent, cela fait onze graphiques au total. Les erreurs XID ne sont pas collectées : aucune source ne convient aux commandes `nvidia-smi` fixes (#8).
- **Thold et Maint** ([docs/alerting.md](../../alerting.md)). Modèles Thold fournis pour la température, la puissance par rapport à sa limite, les indicateurs de limitation, les nouvelles erreurs ECC non corrigées, les retraits de pages en attente et l'âge de l'échantillon. Chaque valeur est une jauge, car Thold 1.8.2 échoue sur un compteur inconnu. Un test de bout en bout avec Thold 1.8.2 et Maint 1.3 non modifiés montre chaque seuil se déclenchant et se rétablissant, y compris lors d'une réinitialisation ECC, et Maint le suspendant.
- **GPU Health** (GPU View). Par nœud : fraîcheur, statut de fin de la dernière collecte, état Maint, backend, versions de l'outil et du pilote, et décompte des GPU ; par GPU : présence, âge de l'échantillon, état ECC et masque brut. Cette page lit uniquement les enregistrements stockés.
- **Permissions.** La suite de tests des permissions couvre chaque fichier de page et chaque action, la révocation dès la requête suivante et les refus qui ne divulguent aucune information.
- **Qualification des pannes et de la restauration.** Des exécutions scriptées de bout en bout provoquent des pannes de l'agent, des identifiants, de l'outil, de sa sortie, de la connexion à la base de données et des écritures RRD, puis restaurent la base de données à partir d'un dump, y compris plus ancien que les fichiers RRD ; un nouveau GPU n'hérite jamais du fichier RRD d'un autre ([docs/backup.md](../../backup.md)).
- **Montée en charge** ([docs/performance.md](../../performance.md)). Un test de montée en charge mesure la durée de la collecte, de l'entrée du serveur de scripts, de la création des objets Cacti, d'un cycle du poller et de la page d'état de santé pour un nombre donné de GPU ; l'intégration continue l'exécute à 100 GPU. Les mesures à 1 000 et 8 000 GPU n'étant pas encore enregistrées, aucun nombre maximal de GPU pris en charge n'est annoncé.
- **Documentation opérateur** ([docs/operations.md](../../operations.md)) : installation, mise à niveau, désinstallation, moindre privilège, valeurs indisponibles et dépannage. Les changements sont listés dans [CHANGELOG.md](../../../CHANGELOG.md).

## Apports du jalon 3

- **Collecte via SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). L'administrateur du nœud ajoute deux lignes `extend` fixes dans `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)) ; le plugin lit NET-SNMP-EXTEND-MIB via les fonctions SNMP de Cacti avec les identifiants propres à l'équipement, SNMPv3 étant recommandé. Il n'exécute rien sur le serveur Cacti ni sur le nœud.
- **Analyse stricte.** Les champs sont lus par position, chaque ligne identifie son GPU par UUID, et un code de sortie non nul, un délai dépassé, une sortie tronquée, une ligne avec un mauvais nombre de champs ou un message d'erreur fusionné fait échouer toute la collecte : les valeurs deviennent `U`, aucun GPU n'est marqué absent et GPU Nodes en indique la raison.
- **Un collecteur après chaque cycle du poller.** Il interroge l'agent de chaque nœud activé, met à jour les enregistrements d'identité et met en cache le dernier échantillon. L'entrée du serveur de scripts lit uniquement ce cache, de sorte qu'un sondage Cacti n'attend jamais un nœud GPU.
- **Objets Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Les modèles et la requête de données *GPU - Devices* sont fournis au format XML et importés lors de l'installation et de la mise à niveau. L'enregistrement d'un nœud attache la requête de données à son équipement existant avec la méthode de réindexation None ; GPU Nodes ne peut créer un équipement que lorsque le nœud n'en possède aucun. Chaque GPU est un index identifié par son constructeur et son UUID, avec une source de données partagée par ses graphiques Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages et Sample Age. Chaque champ du modèle est tracé par au moins un graphique, car Cacti ne stocke que les champs utilisés par un graphique. Le plugin réindexe après chaque changement de GPU, conserve une réindexation ignorée par Cacti lorsque l'équipement était Down ou désactivé pour l'exécuter une fois l'équipement revenu à l'état Up, et crée les graphiques pour les nouveaux GPU.
- **L'historique reste attaché à la carte.** Un GPU absent reste un index : ses graphiques affichent une interruption et reprennent dans le même fichier RRD lorsqu'il revient. Une carte de remplacement dans le même emplacement obtient sa propre source de données. L'action **Remove from Data Query** sur la page du nœud retire un GPU absent de la liste ; ses fichiers RRD et ses graphiques sont conservés jusqu'à ce que vous les supprimiez.
- **Permissions d'équipement sur GPU Nodes.** Un administrateur GPU Admin ne voit, ne modifie, n'exporte et ne déplace que les nœuds et GPU des équipements qu'il est autorisé à voir dans Cacti ; le déplacement d'un GPU nécessite l'accès aux deux nœuds. Un nœud dont l'équipement a été supprimé peut être désactivé ou supprimé.
- **Le modèle de métriques commun** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). La mémoire est en octets, le masque d'événements d'horloge est décodé en indicateurs `thr_*`, et `age` correspond à l'âge de l'horodatage propre au GPU, ou `U` lorsqu'il est inconnu.

L'utilisateur effectuant l'installation (celui du serveur web) doit pouvoir écrire dans `<cacti>/resource/script_server/`, où le plugin place le fichier XML de sa requête de données ; dans le cas contraire, l'installation et la mise à niveau s'arrêtent avec un message et réessaient lors de la tentative suivante. La désinstallation ne supprime les modèles et le fichier XML que si *Remove the plugin's Cacti objects on uninstall* est coché, et ne supprime jamais les fichiers RRD, les équipements, les graphiques ni les sources de données ([docs/operations.md](../../operations.md#uninstall)). Les nœuds rattachés à des collecteurs de données distants ne sont pas collectés avant la phase 3.

## Contenu livré par le jalon 2

- **Une installation protégée.** Sous PHP inférieur à 8.2, l'installation et l'activation sont refusées avec un message indiquant la version requise, et le reste de Cacti continue de fonctionner. `setup.php` et les scripts des pages restent syntaxiquement valides sous PHP 8.0.
- **Cycle de vie.** Installation, mise à niveau, désactivation et désinstallation depuis la page Plugins de Cacti. Les mises à niveau sont ordonnées, reprennent après une interruption et ne mettent à jour la version enregistrée qu'une fois réussies. La désinstallation conserve les tables du plugin, sauf si vous décochez *Keep Plugin Tables on Uninstall* ; elle ne modifie jamais un équipement, une source de données, un graphique ou un fichier RRD de Cacti.
- **Domaines distincts.** *GPU Admin* ouvre **GPU Nodes**, où vous enregistrez des équipements Cacti comme nœuds GPU, confirmez ou rejetez les déplacements de GPU entre nœuds et exportez tous les enregistrements en JSON. *GPU View* ouvre **GPU Inventory**, qui liste uniquement les GPU des équipements que l'utilisateur est autorisé à consulter. Les deux pages se trouvent sous Gestion, et chaque modification passe par une requête POST vérifiée par csrf-magic.
- **Enregistrements d'identité des GPU.** Chaque GPU est indexé par constructeur et UUID. L'index, l'identifiant de bus PCI, le nom, le pilote et l'outil de remontée sont des attributs mis à jour sur place. Un nouvel UUID dans un emplacement connu est signalé comme un remplacement possible et démarre son propre historique ; un GPU vu sur un autre nœud n'est déplacé que lorsqu'un opérateur le confirme ; un GPU absent des collectes réussies est marqué comme absent après un seuil configurable et n'est jamais supprimé. Seuls les UUID de GPU complets NVIDIA sont acceptés pour l'instant ; les instances MIG attendent la décision #13, et AMD la recherche #19. Les règles sont validées par des séquences de jeux de données, puisqu'aucun collecteur ne les alimente encore.
- **Tests et intégration continue.** Suites de tests unitaires et de règles de code source sans GPU ni base de données, vérifications du cycle de vie et des permissions de pages sur Cacti 1.2.31 et la branche 1.2.x actuelle sous PHP 8.2 à 8.5, tâche de refus sous PHP 8.1, vérification syntaxique PHP 8.0 des points d'entrée, PHPStan au niveau 9 et conformité REUSE. Le harnais de test reste provisoire tant que la décision #12 n'est pas arrêtée.

### Installation pour évaluation

Ces étapes installent le code actuel pour l'essayer ; [docs/operations.md](../../operations.md) contient le guide opérateur complet. Elles nécessitent Cacti 1.2.31 ou ultérieur sur PHP 8.2 ou ultérieur.

1. Copiez le dépôt dans `plugins/gpu` sous votre répertoire Cacti. Le répertoire doit se nommer `gpu`.
2. Dans Cacti, ouvrez **Configuration > Plugins**, puis installez et activez **GPU**.
3. Attribuez aux utilisateurs le domaine *GPU View*, *GPU Operator* ou *GPU Admin* sous **Configuration > Utilisateurs**, selon leur rôle. L'utilisateur effectuant l'installation reçoit View et Admin ; Admin inclut l'accès Operator.
4. Configurez l'agent SNMP de chaque nœud GPU comme décrit dans [docs/node-setup.md](../../node-setup.md), puis ajoutez le nœud sous **Gestion > GPU Nodes**.
5. Pour les alertes, importez les modèles Thold fournis comme indiqué dans [docs/alerting.md](../../alerting.md).
6. Ajustez éventuellement **Configuration > Paramètres > GPU** : *Mark Absent After* (3 collectes réussies par défaut, de 1 à 100), *Stale After* (âge maximal d'un échantillon avant que ses valeurs n'affichent `U`) et *Keep Plugin Tables on Uninstall* (activé par défaut).

## Cheminement des métriques vers Cacti

La décision [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) a retenu SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)) : l'agent propre du nœud exécute une commande `nvidia-smi` fixe et le plugin se contente d'en lire le résultat. Le plan compare les options à la section [Méthodes de collecte](../../PLAN.md#collection-paths).

## Cibles de compatibilité

| Composant | Cible |
|---|---|
| Cacti | 1.2.31 ou ultérieur, sans correctifs du cœur |
| PHP | 8.2 ou ultérieur ; `setup.php` reste analysable sous PHP 8.0 afin qu'un environnement d'exécution non pris en charge reçoive un refus plutôt qu'une interface Cacti cassée |
| NVIDIA | Versions de pilotes et d'outils à définir par la recherche [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) ; identifiants de champs DCGM vérifiés aux versions v3.3.9 et v4.7.0 |
| AMD | `amd-smi` sur les versions ROCm à définir par la recherche [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 et Maint 1.3, non modifiés, testés de bout en bout ([docs/alerting.md](../../alerting.md)) |

## Conception

- [docs/PLAN.md](../../PLAN.md) est le plan complet : architecture, faits amont sur lesquels il s'appuie (avec sources), modèle d'identité, frontières de sécurité, calculs de performance, tests et risques.
- [docs/decisions/](../../decisions) contient les dossiers de décision d'architecture (ADR) pour les choix déjà effectués.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) associe chaque identifiant d'exigence à son ticket.

```mermaid
flowchart LR
    NODE[Nœud GPU : snmpd extend exécutant nvidia-smi] -->|Lectures SNMP, v3 recommandé| C[Collecteur]
    C --> CACHE[(Cache du plugin indexé par UUID du GPU)]
    CACHE --> SS[Entrée de requête de données du serveur de scripts]
    SS --> RRD[(Fichiers RRD Cacti, une source de données par GPU)]
    SS -->|poller_output| T[Thold et Maint]
    RRD --> G[Graphiques et permissions Cacti]
```

## Suivi des travaux

- **Les jalons** suivent ceux du plan. Chacun dispose d'un ticket épique listant ses sous-tickets et ses preuves de clôture.
- **Les tickets** sont typés par étiquette : `type: requirement`, `type: research`, `type: decision`, `type: epic`. Les tickets d'exigence énoncent des exigences numérotées et testables (`REQ-AREA-NNN`) ainsi que des critères d'acceptation.
- **Les étiquettes** portent également `area:`, `phase:`, `priority:` et `status:`. Consultez [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Le [tableau de projet](https://github.com/users/somethingwithproof/projects/9) présente l'ensemble des tickets à travers les jalons.

## Contribution

Lisez [CONTRIBUTING.md](../../../CONTRIBUTING.md). Les commits nécessitent une signature DCO (*sign-off*). Les discussions de conception ont lieu dans les tickets ; les décisions ouvertes portent l'étiquette `type: decision`. Les captures de sorties provenant de véritables outils GPU constituent la contribution la plus utile au jalon 1 ; supprimez d'abord les noms d'hôtes, numéros de série et UUID.

## Sécurité

Signalez les vulnérabilités en privé ; consultez [SECURITY.md](../../../SECURITY.md).

## Licence

GNU General Public License v2.0, la même licence que Cacti et ses plugins. Consultez [LICENSE](../../../LICENSE).
