# [Upgrade from v8.1.0-dev to v9.0.0-dev](https://github.com/shopsys/shopsys/compare/v8.1.0...HEAD)

This guide contains instructions to upgrade from version v8.1.0-dev to v9.0.0-dev.

**Before you start, don't forget to take a look at [general instructions](/UPGRADE.md) about upgrading.**
There you can find links to upgrade notes for other versions too.

## [shopsys/framework]

- Before doing any other upgrade instructions, you have to upgrade your application to Symfony Flex as some file paths are changed.
  Follow upgrade instructions in the [separate article](./upgrade-instructions-for-symfony-flex.md) ([#1492](https://github.com/shopsys/shopsys/pull/1492))
  All following upgrade instructions are written for upgraded application with Symfony Flex

### Infrastructure
- check all the phing targets that depend on the new `production-protection` target
    - see [project-base diff](https://github.com/shopsys/project-base/commit/e954f194564c76a2caa97761be48f798afde1a61) to see default files that needs to be updated
    - change namespace in `app/getEnvironment.php` from `Shopsys` to `App`
    - if you use any of the targets in your automated build scripts in production environment, you need to pass the confirmation to the phing using `-D production.confirm.action=y`

- upgrade to PostgreSQL 12 ([#1601](https://github.com/shopsys/shopsys/pull/1601))
    - update `docker/php-fpm/Dockerfile`
    
        ```diff
            # install PostgreSQl client for dumping database
            RUN wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add - && \
                sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt/ $(lsb_release -sc)-pgdg main" > /etc/apt/sources.list.d/PostgreSQL.list' && \
        -       apt-get update && apt-get install -y postgresql-10 postgresql-client-10 && apt-get clean
        +       apt-get update && apt-get install -y postgresql-12 postgresql-client-12 && apt-get clean
        ```
    
    - update `tests/App/Test/Codeception/Module/Db.php`

        ```diff
            public function _afterSuite()
            {
        -       $this->cleanup();
                $this->_loadDump();
            }
        
            public function cleanup()
            {
                /** @var \Tests\App\Test\Codeception\Helper\SymfonyHelper $symfonyHelper */
                $symfonyHelper = $this->getModule(SymfonyHelper::class);
                /** @var \Shopsys\FrameworkBundle\Component\Doctrine\DatabaseSchemaFacade $databaseSchemaFacade */
                $databaseSchemaFacade = $symfonyHelper->grabServiceFromContainer(DatabaseSchemaFacade::class);
                $databaseSchemaFacade->dropSchemaIfExists('public');
        -       $databaseSchemaFacade->createSchema('public');
        +   }
        +
        +   /**
        +    * @inheritDoc
        +    */
        +   public function _loadDump($databaseKey = null, $databaseConfig = null)
        +   {
        +       $this->cleanup();
        +       return parent::_loadDump($databaseKey, $databaseConfig);
            }
        ```

    - upgrading server running in Kubernetes
        - when upgrading production environment you should turn maintenance on `kubectl exec [pod-name-php] -c [container] -- php phing maintenance-on`
        - dump current database by running `kubectl exec [pod-name-postgres] -- bash -c "pg_dump -U [postgres-user] [database-name]" > database.sql` (in case you are using more databases repeat this step for each database)
        - change service version in `kubernetes/deployments/postgres.yml`

            ```diff
                containers:
                    -   name: postgres
            -           image: postgres:10.5-alpine
            +           image: postgres:12.1-alpine
            ``` 
    
        - apply new configuration `kubectl apply -k kubernetes/kustomize/overlays/<overlay>`
        - import dumped data into new database server by running `cat database.sql | kubectl exec -i [pod-name-postgres] -- psql -U [postgres-user] -d [database-name]` (this needs to be done for each database dumped from first step)
        - turn maintenance off `kubectl exec [pod-name-php] -c [container] -- php phing maintenance-off`

    - upgrading server running in Docker
        - dump current database by running `docker-compose exec postgres pg_dumpall -l <database_name> -f /var/lib/postgresql/data/<database_name>.backup` (in case you are using more databases repeat this step for each database)
        - backup current database mounted volume `mv var/postgres-data/pgdata var/postgres-data/pgdata.old`
        - change service version in `docker-compose.yml`
            - you should change it also in all `docker-compose*.dist` files such as:
                - `docker/conf/docker-compose.yml.dist`
                - `docker/conf/docker-compose-mac.yml.dist`
                - `docker/conf/docker-compose-win.yml.dist`

            ```diff
                services:
                    postgres:
            -           image: postgres:10.5-alpine
            +           image: postgres:12.1-alpine
            ``` 

        - rebuild and create containers with `docker-compose up -d --build`
        - import dumped data into new database server by running `docker-compose exec postgres psql -f /var/lib/postgresql/data/<database_name>.backup <database_name>` (this needs to be done for each database dumped from first step)
        - if everything works well you may remove backuped data `rm -r var/postgres-data/pgdata.old`
    - for native installation we recommend to upgrade to version 11 first and then to version 12
        - to prevent unexpected behavior do not try this in production environment before previous testing
        - you should follow official instructions with using [pg_upgrade](https://www.postgresql.org/docs/12/pgupgrade.html) or [pg_dumpall](https://www.postgresql.org/docs/12/app-pg-dumpall.html)
            - [migration to version 11](https://www.postgresql.org/docs/11/release-11.html#id-1.11.6.11.4)
            - [migration to version 12](https://www.postgresql.org/docs/12/release-12.html#id-1.11.6.6.4)
        - do not forget to check for BC breaks which may be introduced for your project

- upgrade to Elasticsearch 7 ([#1602](https://github.com/shopsys/shopsys/pull/1602))
    - first of all we recommend to take a look at [Breaking changes](https://www.elastic.co/guide/en/elasticsearch/reference/7.5/release-notes-7.0.0.html) section in Elasticsearch documentation to prevent failures

    - upgrade your project files [using this diff](https://github.com/shopsys/project-base/commit/6f71d95a58e23bf7ad3368047eb420d80b014f9a)

    - migrate elasticsearch indexes by `php phing elasticsearch-index-migrate`

    - upgrading server running in Kubernetes
        - apply new configuration `kubectl apply -k kubernetes/kustomize/overlays/<overlay>`
        - run elasticsearch migration `kubectl exec -i [pod-name-php-fpm] -- ./phing elasticsearch-index-migrate`
    
    - upgrading server running in Docker
        - rebuild and create containers with `docker-compose up -d --build`
        - run elasticsearch migration `docker-compose exec php-fpm ./phing elasticsearch-index-migrate`
    
    - upgrading native installation we recommend to follow Elasticsearch [documentation](https://www.elastic.co/guide/en/cloud/current/ec-upgrading-v7.html)  

- upgrade the Adminer Docker image to 4.7.6 ([#1717](https://github.com/shopsys/shopsys/pull/1717))
    - change the Docker image of Adminer from `adminer:4.7` to `adminer:4.7.6` in your `docker-compose.yml` config, `docker-compose*.yml.dist` templates and `kubernetes/deployments/adminer.yml`:

        ```diff
        - image: adminer:4.7
        + image: adminer:4.7.6
        ```

    - run `docker-compose up -d` so the new image is pulled and used

- upgrade PHP to version 7.4 ([#1737](https://github.com/shopsys/shopsys/pull/1737))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/32755202185ed04fcf0e50b1d96d2af0fae8d778) to update your project

- upgrade Redis client to version 5.2.1 and Redis server to 5.0 ([#1606](https://github.com/shopsys/shopsys/pull/1606))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/e3adc0c31094b47aca03389ef4fa266977edab25) to update your project

- stop using symfony/web-server-bundle ([#1817](https://github.com/shopsys/shopsys/pull/1817))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/0a47cac6590ee49ad994c082543e65d979818ad4) to update your project
    - command `php bin/console shopsys:server:run` (class `Shopsys\FrameworkBundle\Command\ServerRunForDomainCommand`) was removed
    - command `php bin/console server:start` (class `Shopsys\FrameworkBundle\Command\ServerStartWithCustomRouterCommand`) was removed
    - command `php bin/console server:run` (class `Shopsys\FrameworkBundle\Command\ServerRunWithCustomRouterCommand`) was removed
    - Symfony local server can be used instead. You can read more about how to start using it in [Native Installation Guide](https://docs.shopsys.com/en/latest/installation/native-installation/#run-integrated-http-server)

### Configuration
- add trailing slash to all your localized paths for `front_product_search` route ([#1067](https://github.com/shopsys/shopsys/pull/1067))
    - be aware, if you already have such paths (`hledani/`, `search/`) in your application
    - the change might cause problems with your SEO as well
    - if you are ok with both previous warnings, update your files using [project-base diff](https://github.com/shopsys/project-base/commit/09517e6e41cf4b448b12730a6e3a3753d09c88a3)

- clear cache before any other commands in composer and after docker image is built ([#1820](https://github.com/shopsys/shopsys/pull/1820))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/c1e36bc56e902186c5116119c33700feeba8e0f5) to update your project

### Application

- remove unused twig macros ([#1284](https://github.com/shopsys/shopsys/pull/1284/)):
    - update your project using [project-base diff](https://github.com/shopsys/project-base/commit/30af41a91f75b7485e6c804aaa10a03cb5276224)
    - check your templates if you are extending or importing any of the following templates as imports of unused macros were removed from them:
        - `templates/Admin/Content/Article/detail.html.twig`
        - `templates/Admin/Content/Brand/detail.html.twig`
        - `templates/Admin/Content/Category/detail.html.twig`
        - `templates/Admin/Content/Product/detail.html.twig`
- add optional [Frontend API](https://github.com/shopsys/shopsys/blob/master/docs/frontend-api/introduction-to-frontend-api.md) to your project ([#1445](https://github.com/shopsys/shopsys/pull/1445), [#1486](https://github.com/shopsys/shopsys/pull/1486), [#1493](https://github.com/shopsys/shopsys/pull/1493), [#1489](https://github.com/shopsys/shopsys/pull/1489), [#1757](https://github.com/shopsys/shopsys/pull/1757), [#1731](https://github.com/shopsys/shopsys/pull/1731), [#1736](https://github.com/shopsys/shopsys/pull/1736), [#1742](https://github.com/shopsys/shopsys/pull/1742), [#1788](https://github.com/shopsys/shopsys/pull/1788)):
    - run these steps only in case you have not recently updated to Symfony Flex as is described at beginning of this file
        - add `shopsys/frontend-api` dependency with `composer require shopsys/frontend-api`
        - register necessary bundles in `config/bundles.php`
            ```diff
                Shopsys\FormTypesBundle\ShopsysFormTypesBundle::class => ['all' => true],
            +   Shopsys\FrontendApiBundle\ShopsysFrontendApiBundle::class => ['all' => true],
            +   Overblog\GraphQLBundle\OverblogGraphQLBundle::class => ['all' => true],
            +   Overblog\GraphiQLBundle\OverblogGraphiQLBundle::class => ['dev' => true],
                Shopsys\GoogleCloudBundle\ShopsysGoogleCloudBundle::class => ['all' => true],
            ```
        - add new route file [`config/routes/frontend-api.yaml`](https://github.com/shopsys/shopsys/blob/master/project-base/config/routes/frontend-api.yaml) from GitHub
        - add new route file [`config/routes/dev/frontend-api-graphiql.yaml`](https://github.com/shopsys/shopsys/blob/master/project-base/config/routes/dev/frontend-api-graphiql.yaml) from GitHub
        - copy [type definitions from Github](https://github.com/shopsys/shopsys/tree/master/project-base/config/graphql/types) into `config/graphql/types/` folder
        - copy necessary configuration [shopsys_frontend_api.yaml from Github](https://github.com/shopsys/shopsys/blob/master/project-base/config/packages/shopsys_frontend_api.yaml) to `config/packages/shopsys_frontend_api.yaml`
        - update your `security.yaml` configuration [using this diff](https://github.com/shopsys/project-base/commit/3e9a056f032b3fb49e4aaac912fa89cae13725c6#diff-e092a3a494858e808395bb5a24bb8f83)
    - copy [tests for FrontendApiBundle from Github](https://github.com/shopsys/shopsys/tree/master/project-base/tests/FrontendApiBundle) to your `tests` folder
    - update your `easy-coding-standard.yaml` file:
        - add these in `ObjectCalisthenics\Sniffs\Files\FunctionLengthSniff` part
            ```diff
                - '*/tests/FrontendApiBundle/Functional/Image/ProductImagesTest.php'
                - '*/tests/FrontendApiBundle/Functional/Payment/PaymentsTest.php'
                - '*/tests/FrontendApiBundle/Functional/Transport/TransportsTest.php'
                - '*/tests/FrontendApiBundle/Functional/Order/MultipleProductsInOrderTest.php'
            ```
    - update your `build.xml` [using this diff](https://github.com/shopsys/project-base/commit/02ca46eb77d0c96dc6ff1903f434ebb0537248bd#diff-2cccd7bf48b7a9cc113ff564acd802a8)
    - enable Frontend API for all domains by `./phing frontend-api-enable` command (you can manage domains in `config/packages/frontend_api.yaml`)
- unused `block domain` defined in `Admin/Content/Slider/edit.html.twig` has been removed ([#1437](https://github.com/shopsys/shopsys/pull/1437))
    - in case you are using this block of code you should copy it into your project (see PR mentioned above for more details)

- add access denied url to `config/packages/security.yaml` for users which are not granted with access to the requested page ([#1504](https://github.com/shopsys/shopsys/pull/1504))
    ```diff
         administration:
             pattern: ^/(admin/|efconnect|elfinder)
             user_checker: Shopsys\FrameworkBundle\Model\Security\AdministratorChecker
             anonymous: ~
             provider: administrators
             logout_on_user_change: true
    +        access_denied_url: "/admin/access-denied/"
             form_login:
    ```
    - add new customized route `admin_access_denied` in `RouteConfigCustomization`
    ```diff
         ->customizeByRouteName('admin_domain_list', function (RouteConfig $config) {
             if ($this->isSingleDomain()) {
                $config->skipRoute('Domain list in administration is not available when only 1 domain exists.');
             }
    +    })
    +    ->customizeByRouteName('admin_access_denied', function (RouteConfig $config) {
    +        $config->changeDefaultRequestDataSet('This route serves as "access_denied_url" (see security.yaml) and always redirects to a referer (or dashboard).')
    +           ->setExpectedStatusCode(302);
             });
        }
    ```
    - change expected status code for testing superadmin routes from code `404` to `302`
    ```diff
         if (preg_match('~^admin_(superadmin_|translation_list$)~', $info->getRouteName())) {
             $config->changeDefaultRequestDataSet('Only superadmin should be able to see this route.')
    -           ->setExpectedStatusCode(404);
    +           ->setExpectedStatusCode(302);
    ```
    ```diff
        ->customizeByRouteName('admin_administrator_edit', function (RouteConfig $config) {
            $config->changeDefaultRequestDataSet('Standard admin is not allowed to edit superadmin (with ID 1)')
    -           ->setExpectedStatusCode(404);
    +           ->setExpectedStatusCode(302);
    ```

- update your project to be fully functional with administrator roles stored in database ([#1504](https://github.com/shopsys/shopsys/pull/1504))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/bf4e52ce7f3bc5b9650ab0a66269743af98dafe3) to update your project

- update your project to use refactored FileUpload functionality with added support for multiple files ([#1531](https://github.com/shopsys/shopsys/pull/1531/))
    - there were changes in framework classes, styles and scripts so update your project appropriately:
        - `UploadedFileEntityConfigNotFoundException::getEntityClassOrName()` has been removed
        - `UploadedFileFacade::findUploadedFileByEntity()` and `UploadedFileFacade::getUploadedFileByEntity()` has been removed, use `UploadedFileFacade::getUploadedFilesByEntity()` instead
        - `UploadedFileFacade::uploadFile()` is now protected, use `UploadedFileFacade::manageFiles()` instead
        - `UploadedFileRepository::findUploadedFileByEntity()` and `UploadedFileRepository::getUploadedFileByEntity()` has been removed, use `UploadedFileRepository::getUploadedFilesByEntity()` or `UploadedFileRepository::getAllUploadedFilesByEntity()` instead
        - `UploadedFileFacade::hasUploadedFile()` has been removed
        - `UploadedFileFacade::deleteUploadedFileByEntity()` has been removed use `UploadedFileFacade::deleteFiles()` instead
        - `src/Resources/scripts/admin/mailTemplate.attachmentDelete.js` has been removed
        - `UploadedFileExtension::getUploadedFileByEntity()` and `UploadedFileExtension::hasUploadedFile()` has been removed including its Twig functions `hasUploadedFile` and `getUploadedFile`
        - `UploadedFileExtension::getUploadedFileUrl()` and `UploadedFileExtension::getUploadedFilePreviewHtml()` now expect `UploadedFile` instead of entity that applies also for their Twig functions `uploadedFileUrl` and `uploadedFilePreview`
        - `UploadedFile::setTemporaryFilename()` does not longer accept null
        - `FileUploadType` now requires options `entity`, `file_entity_class` and `file_type` see [documentation](https://docs.shopsys.com/en/latest/introduction/using-form-types/#fileuploadtype) for more info
        - `MailTemplateData::attachment()` and `MailTemplateData::deleteAttachment()` has been replaced by `MailTemplateData::attachments()` that is of type `\Shopsys\FrameworkBundle\Component\UploadedFile\UploadedFileData`
        - `src/Resources/scripts/admin/imageUpload.js` has been renamed to `src/Resources/scripts/admin/fileUpload.js` and all form of word `image` has been changed to `file`
        - `src/Resources/styles/admin/component/list/images.less` has been renamed to `src/Resources/styles/admin/component/list/files.less` and all form of word `image` has been changed to `file`
    - following methods has changed their interface:
        - `UploadedFileEntityConfig::__construct()`
            ```diff
             - public function __construct($entityName, $entityClass)
             + public function __construct(string $entityName, string $entityClass, array $types)
            ```
        - `UploadedFile::__construct()`
            ```diff
             - public function __construct($entityName, $entityId, $temporaryFilename)
             + public function __construct(string $entityName, int $entityId, string $type, string $temporaryFilename, int $position)
            ```
        - `UploadedFileFactory::create()` and `UploadedFileFactoryInterface::create`
            ```diff
             - public function create(string $entityName, int $entityId, array $temporaryFilenames)
             + public function create(string $entityName, int $entityId, string $type, string $temporaryFilename, int $position = 0)
            ```
    - update your project configuration files accordingly:
        - `config/packages/twig.yaml`
            ```diff
                - '@ShopsysFramework/Admin/Form/colorpickerFields.html.twig'
            +   - '@ShopsysFramework/Admin/Form/abstractFileuploadFields.html.twig'
                - '@ShopsysFramework/Admin/Form/fileuploadFields.html.twig'
                - '@ShopsysFramework/Admin/Form/imageuploadFields.html.twig'
            ```
        - `config/uploaded_files.yaml`
            ```diff
            +   # It is best practice to name first type as "default"
            +   #
            +   # Example:
            +   # -   name: mailTemplate
            +   #     class: Shopsys\FrameworkBundle\Model\Mail\MailTemplate
            +   #     types:
            +   #         -   name: default
            +   #             multiple: false
            +
                -   name: mailTemplate
                    class: Shopsys\FrameworkBundle\Model\Mail\MailTemplate
            +       types:
            +           -   name: default
            +               multiple: true
            ```

- contact form has been moved to separate page. You can find the whole new setting in administration (`/admin/contact-form/`), where you can edit main text for contact form. ([#1522](https://github.com/shopsys/shopsys/pull/1522))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/ab412377d40d671db46607a5d5f7b13221e6ba71) to update your project

- javascript assets are managed by webpack and npm ([#1545](https://github.com/shopsys/shopsys/pull/1545), [#1645](https://github.com/shopsys/shopsys/pull/1645))
    - please read [upgrade instruction for webpack](./upgrade-instruction-for-webpack.md)

- update FpJsFormValidator bundle ([#1664](https://github.com/shopsys/shopsys/pull/1664))
    - update your `composer.json`
      ```diff
            "require": {
      -         "fp/jsformvalidator-bundle": "^1.5.1",
      +         "fp/jsformvalidator-bundle": "^1.6.1",
            }
      ```
    - update your `.eslintignore`
      ```diff
        /assets/js/commands/translations/mocks
      + /assets/js/bundles
      ```
    - update your `.gitignore`
      ```diff
        /assets/js/translations.json
      + /assets/js/bundles
      ```

- fix not working popup window on single image ([#1630](https://github.com/shopsys/shopsys/pull/1630))
    - add missing javascript for popup single image for class `js-popup-image`, see [project-base diff](https://github.com/shopsys/project-base/commit/ad7a0a20f094d5e936e4bb503946453c5c89ed18)

- css and other assets are managed by webpack ([#1725](https://github.com/shopsys/shopsys/pull/1725))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/10cc704983e77d6b72d5e444b9414723b100e9ee) to update your project
    - see also [project-base diff](https://github.com/shopsys/project-base/commit/2b195ae29754e03dca297dad6461cee5693eca79) from [#1781](https://github.com/shopsys/shopsys/pull/1781)
    - move content from `src/resources/styles` to `assets/styles`
    - move content from `src/resources/svg` to `assets/public/frontend/svg`
    - move content from `web/assets/frontend/fonts` to `assets/public/frontend/fonts`
    - move content from `web/assets/frontend/images` to `assets/public/frontend/images`
    - move content from `web/assets/admin/fonts` to `assets/public/admin/fonts`
    - move content from `web/assets/admin/images` to `assets/public/admin/images`
    - move content from `web/assets/styleguide/images` to `assets/public/styleguide/images`
    - you should remove the `grunt` target from your `build.xml` file if present
    - add `styles_directory` into your domains config (you can get inspired in [project-base/config/domains.yaml](https://github.com/shopsys/shopsys/blob/master/project-base/config/domains.yaml))
    - change all `asset` function call in your templates
      ```diff
        - asset('assets/**/*.*')
        + asset('public/**/*.*')
      ```
    - replace `<link>` with `getCssVersion()` function call from your templates by tag `{{ encore_entry_link_tags('app') }}` see diffs [base.html.twig](https://github.com/shopsys/project-base/commit/10cc704983e77d6b72d5e444b9414723b100e9ee#diff-1afd3913fe3a88a180385025be96ba0d) and [styleguide.html.twig](https://github.com/shopsys/project-base/commit/10cc704983e77d6b72d5e444b9414723b100e9ee#diff-6617f8f8be6642a73c0c1a6bd3005d6e)
      - but there is no 'media="print"' parameter to set link attribute
      - so we need to upgrade our `assets/styles/frontend/*/print/main.less` and wrap all content to media query `@media print { ... your content ... }`
    - In case you are using google fonts, we need to download font files and avoid for using `@import` from external sources. In future we can make some magics in load time performance using FontLoader etc.
        - Example in `variables.less`
          ```css
            @import url('https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap&subset=latin-ext');
          ```
        - open link in your browser and you will see a lot of `@font-face definitions`
        - copy these you want to use:
            ```css
                /* latin-ext */
                @font-face {
                  font-family: 'Montserrat';
                  font-style: normal;
                  font-weight: 400;
                  font-display: swap;
                  src: local('Montserrat Regular'), local('Montserrat-Regular'), url(https://fonts.gstatic.com/s/montserrat/v14/JTUSjIg1_i6t8kCHKm459Wdhyzbi.woff2) format('woff2');
                  unicode-range: U+0100-024F, U+0259, U+1E00-1EFF, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2C60-2C7F, U+A720-A7FF;
                }
                /* latin-ext */
                @font-face {
                  font-family: 'Montserrat';
                  font-style: normal;
                  font-weight: 700;
                  font-display: swap;
                  src: local('Montserrat Bold'), local('Montserrat-Bold'), url(https://fonts.gstatic.com/s/montserrat/v14/JTURjIg1_i6t8kCHKm45_dJE3gfD_u50.woff2) format('woff2');
                  unicode-range: U+0100-024F, U+0259, U+1E00-1EFF, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2C60-2C7F, U+A720-A7FF;
                }
            ```
        - replace previous `@import` with it
        - open all link `https://fonts.gstatic.com/...` in your browser and it will download font file and save them to `assets/public/frontend/fonts`. We are using [fontName][fontWeigh].[extension] filename syntax.
        - change urls in `variables.less` to:
            ```css
              @font-face {
                  font-family: 'Montserrat';
                  font-style: normal;
                  font-weight: 400;
                  font-display: swap;
                  src: local('Montserrat Regular'), local('Montserrat-Regular'), url(@{path-font}/Montserrat400.woff2) format('woff2');
                  unicode-range: U+0100-024F, U+0259, U+1E00-1EFF, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2C60-2C7F, U+A720-A7FF;
                }
                /* latin-ext */
                @font-face {
                  font-family: 'Montserrat';
                  font-style: normal;
                  font-weight: 700;
                  font-display: swap;
                  src: local('Montserrat Bold'), local('Montserrat-Bold'), url(@{path-font}/Montserrat700.woff2) format('woff2');
                  unicode-range: U+0100-024F, U+0259, U+1E00-1EFF, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2C60-2C7F, U+A720-A7FF;
                }
            ```
        - now you can rebuild your less files `npm run dev` and you should see your font on frontend page
    - use full of the webpack, enjoy!

- add support for Safari ([#1811](https://github.com/shopsys/shopsys/pull/1811))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/4aea8de5cfaaed17d9efb814df9686c6402e67a6) to update your project

- add LiveReload for Webpack ([#1807](https://github.com/shopsys/shopsys/pull/1807))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/404d00c26df1d9a35bf1836fc086381d1bf35ca6) to update your project

- hide variant table header when product is denied for sale (you can skip this if you have custom frontend) ([#1634](https://github.com/shopsys/shopsys/pull/1634))
    - add new condition at product detail file: `templates/Front/Content/Product/detail.html.twig`
        ```diff
        -   {% if product.isMainVariant %}
        +   {% if product.isMainVariant and not product.calculatedSellingDenied %}
        ```

- vats can be created and managed per domains ([#1498](https://github.com/shopsys/shopsys/pull/1498))
    - please read [upgrade instruction for vats per domain](https://github.com/shopsys/shopsys/blob/master/upgrade/upgrade-instruction-for-vats-per-domain.md)

- apply these changes to add support for naming uploaded files([#1547](https://github.com/shopsys/shopsys/pull/1547))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/1a2fb6dff9f111ed36c91114d00d45fe9054baaa) to update your project

    - `MessageData::attachmentsFilepaths` has been replaced by `MessageData::attachments` that accepts array of `UploadedFile`
    - `MailTemplateFacade::getMailTemplateAttachmentsFilepaths()` has been replaced by `MailTemplateFacade::getMailTemplateAttachmentFilepath()` that accepts single `UploadedFile`
    - following methods has changed their interface, update your usages accordingly:
        - `UploadedFileLocator::__construct()`
            ```diff
             - public function __construct($uploadedFileDir, $uploadedFileUrlPrefix, FilesystemInterface $filesystem)
             + public function __construct($uploadedFileDir, FilesystemInterface $filesystem, DomainRouterFactory $domainRouterFactory)
            ```
        - `CustomerMailFacade::__construct()`
            ```diff
             - public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, RegistrationMail $registrationMail)
             + public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, RegistrationMail $registrationMail, UploadedFileFacade $uploadedFileFacade)
            ```
        - `OrderMailFacade::__construct()`
            ```diff
             - public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, OrderMail $orderMail)
             + public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, OrderMail $orderMail, UploadedFileFacade $uploadedFileFacade)
            ```
        - `PersonalDataAccessMailFacade::__construct()`
            ```diff
             - public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, PersonalDataExportMail $personalDataExportMail)
             + public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, PersonalDataExportMail $personalDataExportMail, UploadedFileFacade $uploadedFileFacade)
            ```
        - `ResetPasswordMailFacade::__construct()`
            ```diff
             - public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, ResetPasswordMail $resetPasswordMail)
             + public function __construct(Mailer $mailer, MailTemplateFacade $mailTemplateFacade, ResetPasswordMail $resetPasswordMail, UploadedFileFacade $uploadedFileFacade)
            ```
        - `MailTemplateDataFactory::__construct()`
            ```diff
             - public function __construct(UploadedFileFacade $uploadedFileFacade)
             + public function __construct(UploadedFileDataFactoryInterface $uploadedFileDataFactory)
            ```
        - `Mailer::__construct()`
            ```diff
             - public function __construct(Swift_Mailer $swiftMailer, Swift_Transport $realSwiftTransport)
             + public function __construct(Swift_Mailer $swiftMailer, Swift_Transport $realSwiftTransport, MailTemplateFacade $mailTemplateFacade)
            ```
        - `MessageData::__construct()`
            ```diff
             - public function __construct($toEmail, $bccEmail, $body, $subject, $fromEmail, $fromName, array $variablesReplacementsForBody = [], array $variablesReplacementsForSubject = [], array $attachments = [], $replyTo = null)
             + public function __construct($toEmail, $bccEmail, $body, $subject, $fromEmail, $fromName, array $variablesReplacementsForBody = [], array $variablesReplacementsForSubject = [], array $attachmentsFilepaths = [], $replyTo = null)
            ```
        - `UploadedFileFacade::uploadFile()`
            ```diff
             - protected function uploadFile(object $entity, string $entityName, string $type, array $temporaryFilenames): void
             + protected function uploadFile(object $entity, string $entityName, string $type, string $temporaryFilename, string $uploadedFileName): void
            ```
        - `UploadedFileFacade::uploadFiles()`
            ```diff
             - protected function uploadFiles(object $entity, string $entityName, string $type, array $temporaryFilenames, int $existingFilesCount): void
             + protected function uploadFiles(object $entity, string $entityName, string $type, array $temporaryFilenames, array $uploadedFileNames, int $existingFilesCount): void
            ```
        - `UploadedFileFactory::create()` and `UploadedFileFactoryInterface::create()`
            ```diff
             - public function create(string $entityName, int $entityId, string $type, string $temporaryFilename, int $position = 0): UploadedFile
             + public function create(string $entityName, int $entityId, string $type, string $temporaryFilename, string $uploadedFilename, int $position = 0): UploadedFile
            ```
        - `UploadedFileFactory::createMultiple()` and `UploadedFileFactoryInterface::createMultiple()`
            ```diff
             - public function createMultiple(string $entityName, int $entityId, string $type, array $temporaryFilenames, array $uploadedFilenames, int $existingFilesCount): array
             + public function createMultiple(string $entityName, int $entityId, string $type, array $temporaryFilenames, int $existingFilesCount): array
            ```
        - `UploadedFile::__construct()`
            ```diff
             - public function __construct(string $entityName, int $entityId, string $type, string $temporaryFilename, int $position)
             + public function __construct(string $entityName, int $entityId, string $type, string $temporaryFilename, string $uploadedFilename, int $position)
            ```

 - There is a new base html layout with horizontal menu and product filter placed in left panel, for detail information see [the separate article](upgrade-instructions-for-base-layout.md)
- update your project to use refactored customer structure ([#1543](https://github.com/shopsys/shopsys/pull/1543))
    - database table was changed from `users` to `customer_users`, change ORM mapping for entity `User`
        ```diff
           * @ORM\Table(
        -  *     name="users",
        +  *     name="customer_users",
           *     uniqueConstraints={
        ```
    - there were reorganized `User*` and `Customer*` classes and related methods
        - these classes were renamed and/or moved to different namespace, walk through all code occurrences and process changes
            - `App\Form\Admin\UserFormTypeExtension` to `App\Form\Admin\CustomerUserFormTypeExtension`
            - `Shopsys\FrameworkBundle\Form\Admin\Customer\CustomerFormType` to `Shopsys\FrameworkBundle\Form\Admin\Customer\User\CustomerUserUpdateFormType`
            - `Shopsys\FrameworkBundle\Form\Admin\Customer\UserFormType` to `Shopsys\FrameworkBundle\Form\Admin\Customer\User\CustomerUserFormType`
            - `Shopsys\FrameworkBundle\Form\Admin\CustomerCommunication\CustomerCommunicationFormType` to `Shopsys\FrameworkBundle\Form\Admin\CustomerCommunication\CustomerUserCommunicationFormType`
            - `Shopsys\FrameworkBundle\Model\Customer\CurrentCustomer` to `Shopsys\FrameworkBundle\Model\Customer\User\CurrentCustomerUser`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerData` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserUpdateData`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerDataFactory` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserUpdateDataFactory`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerDataFactoryInterface` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserUpdateDataFactoryInterface`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerIdentifier` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserIdentifier`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerIdentifierFactory` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserIdentifierFactory`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerListAdminFacade` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserListAdminFacade`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerPasswordFacade` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserPasswordFacade`
            - `Shopsys\FrameworkBundle\Model\Customer\Exception\CustomerException` to `Shopsys\FrameworkBundle\Model\Customer\Exception\CustomerUserException`
            - `Shopsys\FrameworkBundle\Model\Customer\Exception\InvalidResetPasswordHashException` to `Shopsys\FrameworkBundle\Model\Customer\Exception\InvalidResetPasswordHashUserException`
            - `Shopsys\FrameworkBundle\Model\Customer\Exception\EmptyCustomerIdentifierException` to `Shopsys\FrameworkBundle\Model\Customer\Exception\EmptyCustomerUserIdentifierException`
            - `Shopsys\FrameworkBundle\Model\Customer\Exception\UserNotFoundException` to `Shopsys\FrameworkBundle\Model\Customer\Exception\CustomerUserNotFoundException`
            - `Shopsys\FrameworkBundle\Model\Customer\FrontendUserProvider` to `Shopsys\FrameworkBundle\Model\Customer\User\FrontendCustomerUserProvider`
            - `Shopsys\FrameworkBundle\Model\Customer\User` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUser`
            - `Shopsys\FrameworkBundle\Model\Customer\UserData` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserData`
            - `Shopsys\FrameworkBundle\Model\Customer\UserDataFactory` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserDataFactory`
            - `Shopsys\FrameworkBundle\Model\Customer\UserDataFactoryInterface` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserDataFactoryInterface`
            - `Shopsys\FrameworkBundle\Model\Customer\CustomerFacade` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFacade`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFactory` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFactory`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFactoryInterface` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFactoryInterface`
            - `Shopsys\FrameworkBundle\Model\Customer\UserRepository` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserRepository`
            - `Shopsys\FrameworkBundle\Model\Product\Pricing\ProductPriceCalculationForUser` to `Shopsys\FrameworkBundle\Model\Product\Pricing\ProductPriceCalculationForCustomerUser`
            - `Tests\App\Functional\Model\Customer\CustomerFacadeTest` to `Tests\App\Functional\Model\Customer\CustomerUserFacadeTest`
        - these methods were moved and/or changed interface
            - `Shopsys\FrameworkBundle\Model\Cart\CartFacade::findCartOfCurrentCustomer()` to `Shopsys\FrameworkBundle\Model\Cart\CartFacade::findCartOfCurrentCustomerUser()`
            - `Shopsys\FrameworkBundle\Model\Cart\CartFacade::getCartOfCurrentCustomerCreateIfNotExists()` to `Shopsys\FrameworkBundle\Model\Cart\CartFacade::getCartOfCurrentCustomerUserCreateIfNotExists()`
            - `Shopsys\FrameworkBundle\Model\Cart\CartFacade::findCartByCustomerIdentifier()` to `Shopsys\FrameworkBundle\Model\Cart\CartFacade::findCartByCustomerUserIdentifier()`
            - `Shopsys\FrameworkBundle\Model\Cart\CartFacade::getCartByCustomerIdentifierCreateIfNotExists()` to `Shopsys\FrameworkBundle\Model\Cart\CartFacade::getCartByCustomerUserIdentifierCreateIfNotExists()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserDataFactory::createFromUser()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserDataFactory::createFromCustomerUser()`
            - `Shopsys\FrameworkBundle\Model\Order\OrderFacade::getCustomerOrderList()` to `Shopsys\FrameworkBundle\Model\Order\OrderFacade::getCustomerUserOrderList()`
            - `Shopsys\FrameworkBundle\Model\Order\OrderRepository::getCustomerOrderList()` to `Shopsys\FrameworkBundle\Model\Order\OrderRepository::getCustomerUserOrderList()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFacade::findUserByEmailAndDomain()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFacade::findCustomerUserByEmailAndDomain()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserRepository::findUserByEmailAndDomain()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserRepository::findCustomerUserByEmailAndDomain()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFacade::getUserById()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFacade::getCustomerUserById()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserRepository::getUserById()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserRepository::getCustomerUserById()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFacade::editByCustomer()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFacade::editByCustomerUser()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserFacade::amendUserDataFromOrder()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserFacade::amendCustomerUserDataFromOrder()`
            - `Shopsys\FrameworkBundle\Model\Customer\UserRepository::getUserRepository()` to `Shopsys\FrameworkBundle\Model\Customer\User\CustomerUserRepository::getCustomerUserRepository()`
        - to keep your tests working you need tu update `UserDataFixture`
            - see [Demo\UserDataFixture diff](https://github.com/shopsys/project-base/commit/9a5b86f91204e6da20e7eeb2680cf3678483ddb5#diff-ccdf8e1de68d2285f963bdfcf1f66e5d)
            - see [Performance\UserDataFixture diff](https://github.com/shopsys/project-base/commit/9a5b86f91204e6da20e7eeb2680cf3678483ddb5#diff-16653e45020c9193be989fd362dc8062)
        - as the `BillingAddress` is being connected with a customer, you are able to remove it from `User`
            ```diff
                public function __construct(
                    BaseUserData $userData,
            -       BillingAddress $billingAddress,
                    ?DeliveryAddress $deliveryAddress
                ) {
            ```
            - when you need `BillingAddress`, you can obtain it via `$userData->getCustomer()->getBillingAddress()`
            - also you need to affect all twig templates to respect a new structure (e.g.)
                ```diff
                -   {% set address = user.billingAddress %}
                +   {% set address = user.customer.billingAddress %}
                ```
        - method `Shopsys\FrameworkBundle\Model\Customer\UserFacade::createCustomerWithBillingAddress()` was extracted to new class `Shopsys\FrameworkBundle\Model\Customer\CustomerUserFacade`
        - review all templates, controllers and form types and rename all occurrences of user to customerUser [see project-base diff](https://github.com/shopsys/project-base/commit/9a5b86f91204e6da20e7eeb2680cf3678483ddb5) for inspiration

- add hover timeout to horizontal menu ([#1564](https://github.com/shopsys/shopsys/pull/1564))
    - you can skip this task if you have your custom design
    - move loader to hidden submenu - so it can not interrupt hover `src/Resources/scripts/frontend/CategoryPanel.js`
        ```diff
            loadCategoryItemContent ($categoryItem, url) {
                Ajax.ajax({
        -           loaderElement: $categoryItem,
        +           loaderElement: $categoryItem.find('.js-category-list-placeholder'),
        ```
    - add new js plugin hoverIntent v1.10.1 `src/Resources/scripts/frontend/plugins/jquery.hoverIntent.js` (https://github.com/shopsys/shopsys/tree/master/project-base/src/Resources/scripts/frontend/plugins/jquery.hoverIntent.js)
    - add new js component `src/Resources/scripts/frontend/components/hoverIntent.js` (https://github.com/shopsys/shopsys/tree/master/project-base/src/Resources/scripts/frontend/components/hoverIntent.js)
    - add class and data attributes to hover menu `templates/Front/Content/Category/panel.html.twig`
        ```diff
          {% for categoryWithLazyLoadedVisibleChildren in categoriesWithLazyLoadedVisibleChildren %}
              {% set isCurrentCategory = (currentCategory is not null and currentCategory == categoryWithLazyLoadedVisibleChildren.category) %}
        -     <li class="list-menu__item js-category-item">
        +     <li class="list-menu__item js-category-item js-hover-intent" data-hover-intent-force-click="true" data-hover-intent-force-click-element=".js-category-collapse-control">
        ```
- allow getting data for FE API from Elastic ([#1557](https://github.com/shopsys/shopsys/pull/1557))
    - add and change fields in your elasticsearch definition files
        ```diff
        -   main_variant
        +   is_main_variant

        +   "uuid": {
        +      "type": "text"
        +   },
        +   "unit": {
        +      "type": "text"
        +   },
        +   "is_using_stock": {
        +      "type": "boolean"
        +   },
        +   "stock_quantity": {
        +      "type": "boolean"
        +   },
        +   "variants": {
        +       "type": "integer"
        +   },
        +   "main_variant_id": {
        +      "type": "integer"
        +   }
        ```
        Be aware that to make this change in production environment you'll need to migrate old structure.
        If you want to know more you can see [this article](../docs/introduction/console-commands-for-application-management-phing-targets.md#elasticsearch-index-migrate)

    - change and include new fields in ProductSearchExportWithFilterRepositoryTest
        ```diff
            'selling_denied',
        -   'main_variant',
        +   'is_main_variant',
            'visibility',
        +   'uuid',
        +   'unit',
        +   'is_using_stock',
        +   'stock_quantity',
        +   'variants',
        +   'main_variant_id',
        ```
    - if you extended these methods in `ProductOnCurrentDomainFacadeInterface`, `ProductOnCurrentDomainFacade` or `ProductOnCurrentDomainElasticFacade`,
     change their definitions (as strict types and typehints were added or changed)
        ```diff
        -   public function getVisibleProductById($productId);
        +   public function getVisibleProductById(int $productId): Product;

        //...

        -   public function getAccessoriesForProduct(Product $product);
        +   public function getAccessoriesForProduct(Product $product): array;

        //...

        -   public function getVariantsForProduct(Product $product);
        +   public function getVariantsForProduct(Product $product): array;

        //...

        -   public function getPaginatedProductsInCategory(ProductFilterData $productFilterData, $orderingModeId, $page, $limit, $categoryId);
        +   public function getPaginatedProductsInCategory(
        +       ProductFilterData $productFilterData,
        +       string $orderingModeId,
        +       int $page,
        +       int $limit,
        +       int $categoryId
        +   ): PaginationResult;

        //...

        -   public function getPaginatedProductsForBrand($orderingModeId, $page, $limit, $brandId);
        +   public function getPaginatedProductsForBrand(
        +       string $orderingModeId,
        +       int $page,
        +       int $limit,
        +       int $brandId
        +   ): PaginationResult;

        //...

        -   public function getPaginatedProductsForSearch($searchText, ProductFilterData $productFilterData, $orderingModeId, $page, $limit);
        +   public function getPaginatedProductsForSearch(
        +       string $searchText,
        +       ProductFilterData $productFilterData,
        +       string $orderingModeId,
        +       int $page,
        +       int $limit
        +   ): PaginationResult;

        //...

        -   public function getSearchAutocompleteProducts($searchText, $limit);
        +   public function getSearchAutocompleteProducts(?string $searchText, int $limit): PaginationResult;

        //...

        -   public function getProductFilterCountDataInCategory($categoryId, ProductFilterConfig $productFilterConfig, ProductFilterData $productFilterData);
        +   public function getProductFilterCountDataInCategory(
        +       int $categoryId,
        +       ProductFilterConfig $productFilterConfig,
        +       ProductFilterData $productFilterData
        +   ): ProductFilterCountData;

        //...

        -   public function getProductFilterCountDataForSearch($searchText, ProductFilterConfig $productFilterConfig, ProductFilterData $productFilterData);
        +   public function getProductFilterCountDataForSearch(
        +       ?string $searchText,
        +       ProductFilterConfig $productFilterConfig,
        +       ProductFilterData $productFilterData
        +   ): ProductFilterCountData;
        ```

- fix footer advert background and image position ([#1590](https://github.com/shopsys/shopsys/pull/1590))
    - if you have custom design you can skip this
    - add footer modification to `src/Resources/styles/front/common/components/in/place.less`
        ```diff
              .in-place {
                  margin-bottom: @in-place-margin;
                  text-align: center;
        +
        +         &--footer {
        +             padding: 10px 0;
        +             margin-bottom: 0;
        +         }
            }
        ```

    - remove class `.in-place.in-place--footer` in `src/Resources/styles/front/common/todo.less` as it's no longer necessary
        ```diff
        - .in-place.in-place--footer {
        -     padding-top: 10px;
        - }
        ```

    - change classes in `templates/Front/Layout/footer.html.twig`
        ```diff
        - <div class="web__line web__line--split dont-print">
        -     <div class="web__container footer__top">
        + <div class="web__line web__line--grey dont-print">
        +     <div class="web__container">
                  {{ render(controller('App\\Controller\\Front\\AdvertController:boxAction',{'positionName' : 'footer'})) }}
              </div>
          </div>
        ```
- add cart detail on hover ([#1565](https://github.com/shopsys/shopsys/pull/1565))
  
  - you can skip this task if you have your custom design
  - Add new file [`src/Resources/styles/front/common/layout/header/cart-detail.less`](https://github.com/shopsys/shopsys/blob/master/project-base/src/Resources/styles/front/common/layout/header/cart-detail.less)
  - Update your `src/Resources/styles/front/common/layout/header/cart.less` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-bc98fd209f1c026440cbf870086beece)
  - Update your `src/Resources/styles/front/common/main.less`
      ```diff
        @import "layout/header/cart.less";
      + @import "layout/header/cart-detail.less";
        @import "layout/header/cart-mobile.less";
      ```
  - Add new file [`templates/Front/Inline/Cart/cartBoxItemMacro.html.twig`](https://github.com/shopsys/shopsys/blob/master/project-base/templates/Front/Inline/Cart/cartBoxItemMacro.html.twig)
  - Update your `templates/Front/Inline/Cart/cartBox.html.twig` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-41605908c87d6192f16bdf03da67b192)
  - Update your `templates/Front/Layout/header.html.twig` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-fec16681aa60ba908bc8e574d24de3fd)
  - Add new file [`assets/js/frontend/cart/cartBoxItemRemover.js`](https://github.com/shopsys/shopsys/blob/master/project-base/assets/js/frontend/cart/cartBoxItemRemover.js)
  - Update `assets/js/frontend/cart/cartBox.js`
      ```diff
        Ajax.ajax({
            loaderElement: '#js-cart-box',
            url: $(event.currentTarget).data('reload-url'),
      +     data: { 'isIntentActive': $(event.currentTarget).hasClass('active'), loadItems: true },
            type: 'get',
            success: function (data) {
                $('#js-cart-box').replaceWith(data);
                ...
            }
        });
      ```
  
  - Update `assets/js/frontend/cart/index.js`
      ```diff
        import './cartRecalculator';
      + import './CartBoxItemRemover';
      ```
  
  - Update your `src/Controller/Front/CartController.php` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-2cc95b0ea7402f2767d208da32b41333)
  
  - Update your `config/routes/shopsys_front.yaml`
      ```diff
      + front_cart_delete_ajax:
      +     path: /cart/delete-ajax/{cartItemId}/
      +     defaults:
      +         _controller: App\Controller\Front\CartController:deleteAjaxAction
      +     requirements:
      +         cartItemId: \d+
      +     condition: "request.isXmlHttpRequest()"
      + front_cart_box_detail:
      +     path: /cart/box-detail
      +     defaults:
      +         _controller: App\Controller\Front\CartController:boxDetailAction
      ```

  - Update your `assets/js/frontend/components/hoverIntent.js` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-0c8ac3a092aa65b5548bba44aaf47934)

  - Update your `tests/App/Acceptance/acceptance/CartCest.php` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-1cdd5de922474f9286fd26767312abe6) 
  - Update your `tests/App/Acceptance/acceptance/PageObject/Front/CartPage.php` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-22d067f5c4b216b5f2809f6d6340bfee)
  - Update your `tests/App/Acceptance/acceptance/OrderCest.php` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-d697251fab7d514841306ad608a65fc5)
  - Update your `tests/App/Acceptance/acceptance/PageObject/Front/OrderPage.php` like in the [diff](https://github.com/shopsys/shopsys/pull/1565/files#diff-d2e52049c05d13eea5291229d1a2e6da)

- set loaderElement of searchAutocomplete component to search button (removed from body) [#1626](https://github.com/shopsys/shopsys/pull/1626)
    - update your `assets/js/frontend/components/searchAutocomplete.js`
        ```diff
          Ajax.ajaxPendingCall('Shopsys.search.autocomplete.searchRequest', {
        -     loaderElement: null,
        +     loaderElement: '.js-search-autocomplete-submit',
            // ...
        });
        ```
    - update your `templates/Front/Content/Search/searchBox.html.twig`
        ```diff
        -  <button type="submit" class="btn search__form__button">
        +  <button type="submit" class="btn search__form__button js-search-autocomplete-submit">
               {{ 'Search [verb]'|trans }}
           </button>
        ```
- fix domain icon rendering and loading ([#1655](https://github.com/shopsys/shopsys/pull/1655))
    - remove trailing slash in `config/paths.yaml`
        ```diff
        -   shopsys.domain_images_url_prefix: '/%shopsys.content_dir_name%/admin/images/domain/'
        +   shopsys.domain_images_url_prefix: '/%shopsys.content_dir_name%/admin/images/domain'
        ```
    - fix icon path in `ImageDataFixture` (`src/DataFixtures/Demo/ImageDataFixture.php`)
        ```diff
            public function load(ObjectManager $manager)
            {
                $this->truncateImagesFromDb();
                if (file_exists($this->dataFixturesImagesDirectory)) {
        -           $this->moveFilesFromLocalFilesystemToFilesystem($this->dataFixturesImagesDirectory . 'domain/', $this->targetDomainImagesDirectory);
        +           $this->moveFilesFromLocalFilesystemToFilesystem($this->dataFixturesImagesDirectory . 'domain/', $this->targetDomainImagesDirectory . '/');
        ```
- check your custom form types with currencies after Money input ([#1675](https://github.com/shopsys/shopsys/pull/1675))
    - form field option `currency` is now rendered with `appendix_block` block (inside span tag) instead of plain text

- update your project to use refactored Elasticsearch related classes ([#1622](https://github.com/shopsys/shopsys/pull/1622))
    - Phing targets related to Elasticsearch were renamed, change usages
        - `product-search-create-structure` was removed, use `elasticsearch-index-create`
        - `product-search-delete-structure` was removed, use `elasticsearch-index-delete`
        - `product-search-export-products` was removed, use `elasticsearch-export`
        - `product-search-migrate-structure` was removed, use `elasticsearch-index-migrate`
        - `product-search-recreate-structure` was removed, use `elasticsearch-index-recreate`
        - `test-product-search-create-structure` was removed, use `test-elasticsearch-index-create`
        - `test-product-search-delete-structure` was removed, use `test-elasticsearch-index-delete`
        - `test-product-search-export-products` was removed, use `test-elasticsearch-export`
        - `test-product-search-recreate-structure` was removed, use `test-elasticsearch-index-recreate`
    - update `config/services/cron.yaml` if you have registered products export by yourself

        ```diff
        -   Shopsys\FrameworkBundle\Model\Product\Search\Export\ProductSearchExportCronModule:
        +   Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductExportCronModule:
        ```

    - update `config/services_test.yaml`
    
        ```diff
        -   Shopsys\FrameworkBundle\Model\Product\Search\Export\ProductSearchExportWithFilterRepository: ~
        +   Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductExportRepository: ~
        ```

    - remove `\Tests\App\Functional\Component\Elasticsearch\ElasticsearchStructureUpdateCheckerTest`
    - update `ProductSearchExportWithFilterRepositoryTest`
        - move the class from `\Tests\App\Functional\Model\Product\Search\ProductSearchExportWithFilterRepositoryTest` to `Tests\App\Functional\Model\Product\Elasticsearch\ProductExportRepositoryTest`
        - update annotation for property `$repository`

            ```diff
                /**
            -    * @var \Shopsys\FrameworkBundle\Model\Product\Search\Export\ProductSearchExportWithFilterRepository
            +    * @var \Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductExportRepository
                 * @inject
                 */
                private $repository;
            ```

        - remove unused argument of method `getExpectedStructureForRepository()` and all its usages

            ```diff
                /**
            -    * @param \Shopsys\FrameworkBundle\Model\Product\Search\Export\ProductSearchExportWithFilterRepository $productSearchExportRepository
                 * @return string[]
                 */
            -   private function getExpectedStructureForRepository(ProductSearchExportWithFilterRepository $productSearchExportRepository): array
            +   private function getExpectedStructureForRepository(): array
            ```

    - update `FilterQueryTest`
        - define `use` statement for `ProductIndex`

            ```diff
                use Shopsys\FrameworkBundle\Component\Money\Money;
            +   use Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductIndex;
                use Shopsys\FrameworkBundle\Model\Product\Listing\ProductListOrderingConfig;
            ```

        - inject `IndexDefinitionLoader` instead of removed `ElasticsearchStructureManager`

            ```diff
                /**
            -    * @var \Shopsys\FrameworkBundle\Component\Elasticsearch\ElasticsearchStructureManager
            +    * @var \Shopsys\FrameworkBundle\Component\Elasticsearch\IndexDefinitionLoader
                 * @inject
                 */
            -   private $elasticSearchStructureManager;
            +   private $indexDefinitionLoader;
            ```

        - update `createFilter()` method

            ```diff
                protected function createFilter(): FilterQuery
                {
            -       $elasticSearchIndexName = $this->elasticSearchStructureManager->getAliasName(Domain::FIRST_DOMAIN_ID, self::ELASTICSEARCH_INDEX);
            -       $filter = $this->filterQueryFactory->create($elasticSearchIndexName);
            +       $indexDefinition = $this->indexDefinitionLoader->getIndexDefinition(ProductIndex::getName(), Domain::FIRST_DOMAIN_ID);
            +       $filter = $this->filterQueryFactory->create($indexDefinition->getIndexAlias());

                    return $filter->filterOnlySellable();
                }
            ```
- update your project to export to Elasticsearch only changed products ([#1636](https://github.com/shopsys/shopsys/pull/1636))
    - if you have registered products export by yourself, you can update `config/services/cron.yaml` to run frequently export of only changed products and full export at midnight
    ```diff
        Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductExportCronModule:
            tags:
    -           - { name: shopsys.cron, hours: '*', minutes: '*' }
    +           - { name: shopsys.cron, hours: '0', minutes: '0' }

    +   Shopsys\FrameworkBundle\Model\Product\Elasticsearch\ProductExportChangedCronModule:
    +       tags:
    +           - { name: shopsys.cron, hours: '*', minutes: '*' }
    ```
- update your application to support multiple delivery addresses ([#1635](https://github.com/shopsys/shopsys/pull/1635))
    - some methods has changed so you might want to update their usage in your application:
        - `Customer::getDeliveryAddress()` and `Customer::setDeliveryAddress()` has been removed you can use `Customer::getDeliveryAddresses()` or `CustomerUser::getDefaultDeliveryAddress()` instead
        - `CustomerUserFacade::editDeliveryAddress()` has been removed, use `DeliveryAddressFacade::edit()` instead
        - `CustomerUser::__construct()`
            ```diff
            -   public function __construct(CustomerUserData $customerUserData, ?DeliveryAddress $deliveryAddress)
            +   public function __construct(CustomerUserData $customerUserData)
            ```
        - `CustomerUserFacade::__construct()`
            ```diff
                public function __construct(
                    EntityManagerInterface $em,
                    CustomerUserRepository $customerUserRepository,
                    CustomerUserUpdateDataFactoryInterface $customerUserUpdateDataFactory,
                    CustomerMailFacade $customerMailFacade,
                    BillingAddressFactoryInterface $billingAddressFactory,
                    DeliveryAddressFactoryInterface $deliveryAddressFactory,
                    BillingAddressDataFactoryInterface $billingAddressDataFactory,
                    CustomerUserFactoryInterface $customerUserFactory,
                    CustomerUserPasswordFacade $customerUserPasswordFacade,
            -       CustomerFacade $customerFacade
            +       CustomerFacade $customerFacade,
            +       DeliveryAddressFacade $deliveryAddressFacade
                ) {
            ```
        - `CustomerUserFacade::createCustomerUser()`
            ```diff
                protected function createCustomerUser(
                    Customer $customer,
            -       CustomerUserData $customerUserData,
            -       ?DeliveryAddressData $deliveryAddressData = null
            +       CustomerUserData $customerUserData
                ): CustomerUser
            ```
        - `CustomerUserFacade::amendCustomerUserDataFromOrder()` 
            ```diff
              -   public function amendCustomerUserDataFromOrder(CustomerUser $customerUser, Order $order)
              +   public function amendCustomerUserDataFromOrder(CustomerUser $customerUser, Order $order, ?DeliveryAddress $deliveryAddress) 
            ```
        - `CustomerUserFacade::edit()`
            ```diff
              -   protected function edit(int $customerUserId, CustomerUserUpdateData $customerUserUpdateData)
              +   protected function edit(int $customerUserId, CustomerUserUpdateData $customerUserUpdateData, ?DeliveryAddress $deliveryAddress = null)
            ```
        - `CustomerUserFactory::create() and CustomerUserFactoryInterface::create()`
            ```diff
            -    public function create(CustomerUserData $customerUserData, ?DeliveryAddress $deliveryAddress): CustomerUser
            +    public function create(CustomerUserData $customerUserData): CustomerUser
            ```
        - `CustomerUserUpdateDataFactory::createAmendedByOrder()` and `CustomerUserUpdateDataFactoryInterface::createAmendedByOrder()`
            ```diff
              -   public function createAmendedByOrder(CustomerUser $customerUser, Order $order): CustomerUserUpdateData
              +   public function createAmendedByOrder(CustomerUser $customerUser, Order $order, ?DeliveryAddress $deliveryAddress): CustomerUserUpdateData
            ```
        - `OrderFacade::createOrderFromFront()`
            ```diff
              -   public function createOrderFromFront(OrderData $orderData)
              +   public function createOrderFromFront(OrderData $orderData, ?DeliveryAddress $deliveryAddress)
            ```
    - there has been changes in project files, that you should apply in your project:
        - see [project-base diff](https://github.com/shopsys/project-base/commit/2b6e375899d0d95b79407991fffef55bd6bb0392) to update your project

- fix functional tests for single domain usage ([#1682](https://github.com/shopsys/shopsys/pull/1682))
    - if you do not plan use your project configured with single domain you may skip this
    - add following method into `tests/App/Functional/Model/Order/OrderTransportAndPaymentTest.php`, `tests/App/Functional/Model/Payment/IndependentPaymentVisibilityCalculationTest.php`, `tests/App/Functional/Model/Transport/IndependentTransportVisibilityCalculationTest.php`

        ```php
        /**
         * @param bool[] $enabledForDomains
         * @return bool[]
         */
        private function getFilteredEnabledForDomains(array $enabledForDomains): array
        {
            return array_intersect_key($enabledForDomains, array_flip($this->domain->getAllIds()));
        }
        ```

        - find in those classes assignments into `TransportData::enabled` and `PaymentData::enabled` and filter the array by added method `getFilteredEnabledForDomains()` - like in examples bellow

        ```diff
        -   $transportData->enabled = $enabledForDomains;
        +   $transportData->enabled = $this->getFilteredEnabledForDomains($enabledForDomains);
        ```

        ```diff
        -   $paymentData->enabled = [
        +   $paymentData->enabled = $this->getFilteredEnabledForDomains([
                self::FIRST_DOMAIN_ID => true,
                self::SECOND_DOMAIN_ID => false,
        -   ];
        +   ]);
        ```

    - skip test when only one domain is configured with adding code bellow at the beginning of test in
        - `Tests\App\Functional\Model\Payment\PaymentDomainTest::testCreatePaymentWithDifferentVisibilityOnDomains()`
        - `Tests\App\Functional\Model\Transport\TransportDomainTest::testCreateTransportWithDifferentVisibilityOnDomains()`
        
            ```php
            if (count($this->domain->getAllIds()) === 1) {
                $this->markTestSkipped('Test is skipped for single domain');
            }
            ```
- update your code to have easier extension of customer related classes ([#1700](https://github.com/shopsys/shopsys/pull/1700/))
    - `Customer::addBillingAddress()` and `Customer::addDeliveryAddress()` are no longer public, use `CustomerFacade::create()` and `CustomerFacade::edit()` methods instead
    - `CustomerFacade::createCustomerWithBillingAddress()` no longer exists, use `CustomerFacade::create()` and `BillingAddressFacade::create()` instead
    - some methods have changed their interface, update your code usages:
        - `Customer::__construct()`
            ```diff
            -   public function __construct()
            +   public function __construct(CustomerData $customerData)
            ```
        - `CustomerFacade::__construct()`
            ```diff
            -   public function __construct(EntityManagerInterface $em, CustomerFactoryInterface $customerFactory, BillingAddressFactoryInterface $billingAddressFactory)
            +   public function __construct(EntityManagerInterface $em, CustomerFactoryInterface $customerFactory, CustomerRepository $customerRepository)
            ```
        - `CustomerFactory::create()` and `CustomerFactoryInterface::create()`
            ```diff
            -   public function create(): Customer
            +   public function create(CustomerData $customerData): Customer
            ```
        - `CustomerUserFacade::__construct()`
            ```diff
                CustomerFacade $customerFacade,
            -   DeliveryAddressFacade $deliveryAddressFacade
            +   DeliveryAddressFacade $deliveryAddressFacade,
            +   CustomerDataFactoryInterface $customerDataFactory,
            +   BillingAddressFacade $billingAddressFacade
            ```
    - `tests/App/Functional/PersonalData/PersonalDataExportXmlTest.php` has been changed, see [project-base diff](https://github.com/shopsys/project-base/commit/efd91f6dcc837445cfd772e9b6b9ff714f0b5652) to update it

- update your application to refresh administrator roles after edit own profile ([#1514](https://github.com/shopsys/shopsys/pull/1514))
    - some methods has changed so you might want to update their usage in your application:
        - `AdministratorController::__construct()`
            ```diff
                public function __construct(
                    AdministratorFacade $administratorFacade,
                    GridFactory $gridFactory, GridFactory $gridFactory,
                    BreadcrumbOverrider $breadcrumbOverrider,
                    AdministratorActivityFacade $administratorActivityFacade,
            -       AdministratorDataFactoryInterface $administratorDataFactory
            +       AdministratorDataFactoryInterface $administratorDataFactory,
            +       AdministratorRolesChangedFacade $administratorRolesChangedFacade
                 )
            ```
        - `AdministratorController::editAction()`
            ```diff
            -   public function editAction(Request $request, $id)
            +   public function editAction(Request $request, int $id)
            ```
        - `AdministratorRolesChangedSubscriber::__construct()`
            ```diff
            -    public function __construct(TokenStorageInterface $tokenStorage, AdministratorFacade $administratorFacade)
            +    public function __construct(TokenStorageInterface $tokenStorage, AdministratorRolesChangedFacade $administratorRolesChangedFacade)
            ```

- add cron overview ([#1407](https://github.com/shopsys/shopsys/pull/1407))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/fdac77abc9fd7f167ccd544f4691ee25b2de169d) to update your project
    - add `readableName` attribute for your crons in `cron.yaml` file as described in diff above

- update your application to do not change product availability to default when availability can not be calculated immediately ([#1659](https://github.com/shopsys/shopsys/pull/1659))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/0be925148d15222e8765efae38386afef1485ebf) to update your project
    
- update your application to have czech crowns on czech domain and euro on english domain ([#1542](https://github.com/shopsys/shopsys/pull/1542))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/837c86055b6b1e433b65b7cb28d70104ac15c765) to update your project
    

- update your application to symfony4 ([#1704](https://github.com/shopsys/shopsys/pull/1704))
    
    - see [project-base diff](https://github.com/shopsys/project-base/commit/fb3fa6f0c94252c6f51dbf4487bb2964aa4c64b7) to update your project
    - see also [project-base diff](https://github.com/shopsys/project-base/commit/cd37af7d834218d58ddbf1e620e661d8cd441b27) from [#1764](https://github.com/shopsys/shopsys/pull/1764)
    
    - minimum memory requirements for installation using Docker on MacOS and Windows has changed, please read  [Installation Using Docker for MacOS](docs/installation/installation-using-docker-macos.md) or [Installation Using Docker for Windows 10 Pro and higher](docs/installation/installation-using-docker-windows-10-pro-higher.md)
    
    - some methods has changed so you might want to update their usage in your application:
    
        - `RouterDebugCommandForDomain::__construct()`
            ```diff
            -   public function __construct(DomainChoiceHandler $domainChoiceHelper, $router = null)
            +   public function __construct(DomainChoiceHandler $domainChoiceHelper, RouterInterface $router, ?FileLinkFormatter $fileLinkFormatter = null)
            ```
        - `RouterDebugCommandForDomain::__execute()`
            ```diff
            -   protected function execute(InputInterface $input, OutputInterface $output)
            +   protected function execute(InputInterface $input, OutputInterface $output): int
            ```
        - `RouterMatchCommandForDomain::__construct()`
            ```diff
            -   public function __construct(DomainChoiceHandler $domainChoiceHelper, $router = null)
            +   public function __construct(DomainChoiceHandler $domainChoiceHelper, RouterInterface $router)
            ```
        - `RouterMatchCommandForDomain::execute()`
            ```diff
            -   protected function execute(InputInterface $input, OutputInterface $output)
            +   protected function execute(InputInterface $input, OutputInterface $output): int
            ```
        - `ConfirmDeleteResponseFactory::__construct()`
            ```diff          
                public function __construct(
            -       EngineInterface $templating,
            +       Environment $twigEnvironment,
                    RouteCsrfProtector $routeCsrfProtector
                )
            ```
        - `FilesystemLoader::__construct`
            ```diff
                public function __construct(
            -       FileLocatorInterface $locator,
            -       TemplateNameParserInterface $parser,
                    ?string $rootPath = null,
                    ?Domain $domain = null
                ) {
            ```
        - `ErrorExtractor::getAllErrorsAsArray()`
            ```diff           
            -   public function getAllErrorsAsArray(Form $form, Bag $flashMessageBag)
            +   public function getAllErrorsAsArray(FormInterface $form, array $errorFlashMessages): array
            ```
        - `Grid::__construct()`
            ```diff           
                public function __construct(
                    $id,
                    DataSourceInterface $dataSource,
                    RequestStack $requestStack,
                    RouterInterface $router,
                    RouteCsrfProtector $routeCsrfProtector,
            -       Twig_Environment $twig
            +       Environment $twig
                )
            ```
        - `GridFactory::__construct()`
            ```diff
                public function __construct(
                    RequestStack $requestStack,
                    RouterInterface $router,
                    RouteCsrfProtector $routeCsrfProtector,
            -       Twig_Environment $twig
            +       Environment $twig
                )
            ```
        - `GridView::__construct()`
            ```diff
                public function __construct(
                    Grid $grid,
                    RequestStack $requestStack,
                    RouterInterface $router,
            -       Twig_Environment $twig,
            +       Environment $twig,
                    $theme,
                    array $templateParameters = []
                )
            ```
        - `CustomTransFiltersVisitor::doEnterNode()`
            ```diff
            -   protected function doEnterNode(Twig_Node $node, Twig_Environment $env)
            +   protected function doEnterNode(Node $node, Environment $env)
            ```
        - `CustomTransFiltersVisitor::replaceCustomFilterName()`
            ```diff
            -   protected function replaceCustomFilterName(Twig_Node_Expression_Filter $filterExpressionNode, $newFilterName)
            +   protected function replaceCustomFilterName(FilterExpression $filterExpressionNode, $newFilterName)
            ```
        - `CustomTransFiltersVisitor::doLeaveNode()`
            ```
            -   protected function doLeaveNode(Twig_Node $node, Twig_Environment $env)
            +   protected function doLeaveNode(Node $node, Environment $env)
            ```
        - `CartWatcherFacade::__construct()`
            ```diff
                public function __construct(
            -       FlashMessageSender $flashMessageSender,
            +       FlashBagInterface $flashBag,
                    EntityManagerInterface $em,
                    CartWatcher $cartWatcher,
            -       CurrentCustomerUser $currentCustomerUser
            +       CurrentCustomerUser $currentCustomerUser,
            +       Environment $twigEnvironment
                ) {
            ```
        - `ContactFormFacade::__construct()`
            ```diff             
                public function __construct(
                    MailSettingFacade $mailSettingFacade,
                    Domain $domain,
                    Mailer $mailer,
            -       Twig_Environment $twig
            +       Environment $twig
                ) {
            ```
        - `FeedRenderer::__construct()`
            ```diff
            -   public function __construct(Twig_Environment $twig, Twig_TemplateWrapper $template)
            +   public function __construct(Environment $twig, TemplateWrapper $template)
            ```
        - `FeedRendererFactory::__construct()`
            ```diff
            -   public function __construct(Twig_Environment $twig)
            +   public function __construct(Environment $twig)
            ```
        - `OrderMail::__construct()`
            ```diff
                public function __construct(
                    Setting $setting,
                    DomainRouterFactory $domainRouterFactory,
            -       Twig_Environment $twig,
            +       Environment $twig,
                    OrderItemPriceCalculation $orderItemPriceCalculation,
                    Domain $domain,
                    PriceExtension $priceExtension,
            ```
        - `Authenticator::__construct()`
            ```diff
                public function __construct(
            -       TokenStorage $tokenStorage,
            -       TraceableEventDispatcher $traceableEventDispatcher
            +       TokenStorageInterface $tokenStorage,
            +       EventDispatcherInterface $eventDispatcher
              ) {
            ```
        - `ImageExtension::__construct()`
            ```diff
                public function __construct(
                    $frontDesignImageUrlPrefix,
                    Domain $domain,
                    ImageLocator $imageLocator,
                    ImageFacade $imageFacade,
            -       EngineInterface $templating,
            +       Environment $twigEnvironment,
                    bool $isLazyLoadEnabled = false
                ) {
            ```
        - `MailerSettingExtension::__construct()`
            ```diff         
            -   public function __construct(ContainerInterface $container, EngineInterface $templating)
            +   public function __construct(ContainerInterface $container, Environment $twigEnvironment)
           ```
    
    - some methods was removed
        - `AdminBaseController.php::getFlashMessageSender` (you can use `FlashMessageTrait`)
        
    - these classes were removed so you might need to update your application appropriately:
        - `Bag`(you can use `FlashMessageTrait`)
        - `BagNameIsNotValidException`
        - `FlashMessageException`
        - `FlashMessageSender` (you cn use `FlashMessageTrait`)
        - `CannotConvertToJsonException`
        - `ConstantNotFoundException`
        - `JsConstantCompilerException`
        - `JsConstantCompilerPass`
        - `JsCompiler`
        - `JsCompilerPassInterface`
        - `JsTranslatorCompilerPass`
        - `JsConstantCallParserException`
        - `JsConstantCall`
        - `JsConstantCallParser`
        - `JsParserException`
        - `UnsupportedNodeException`
        - `JsFunctionCallParser`
        - `JsStringParser`
        - `JsTranslatorCallParserException`
        - `JsTranslatorCall`
        - `JsTranslatorCallParser`
        - `JsTranslatorCallParserFactory`
        - `JavascriptCompiler`
        - `NotLogFakeHttpExceptionsExceptionListener` (you can use `NotLogFakeHttpExceptionsErrorListener`)
      
    - these constants were removed so you might need to update your application appropriately:
        - `Roles::ROLE_ADMIN_AS_CUSTOMER`


- fix your version of jms/translation-bundle to 1.4.4 in order to prevent problems with translations dumping ([#1732](https://github.com/shopsys/shopsys/pull/1732))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/55c113d079e61ee399ad5619f142f1f286808155) to update your project

- fix your password minimum length constraint message ([#1478](https://github.com/shopsys/shopsys/pull/1478))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/89eace30cb0d3d3e18de49d9085deb2e6cd02161) to update your project

- fix your translations ids ([#1738](https://github.com/shopsys/shopsys/pull/1738))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/cbfa2d20aba1a455ce29ae0c81e55a09ff30ffc7) to update your project
    - email template variable has been changed from `{e-mail}` to `{email}`, update your email templates accordingly
    - run `php phing translations-dump` and check, if some translations are needed to be translated

- method name in `HeurekaCategoryDownloader` has been changed ([#1740](https://github.com/shopsys/shopsys/pull/1740))
    - `HeurekaCategoryDownloader::convertToShopEntities()` has been renamed to `HeurekaCategoryDownloader::convertToHeurekaCategoriesData()` update your project appropriately

- move cron definitions in your project so it is easier to control them ([#1739](https://github.com/shopsys/shopsys/pull/1739))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/e997bae209a0af2b08554bd9cacaaa64fed29442) to update your project
    
- compliance with the principle of encapsulation in the project base ([#1640](https://github.com/shopsys/shopsys/pull/1640))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/5311d3568f8a2d935528dbd25594a0530142ac4a) to update your project

- remove unused import in `tests/App/Functional/Model/Product/Availability/ProductAvailabilityCalculationTest.php` ([#1779](https://github.com/shopsys/shopsys/pull/1779))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/0904a42718fbb9997183bcd19d9be2deec9f9367) to update your project

- remove unnecessary entity extensions from parameters_common.yaml ([#1663](https://github.com/shopsys/shopsys/pull/1663))
    - there is no longer need to register entities in `App` namespace extending entities from `Shopsys` namespace, so remove all necessary uses, see [project-base diff](https://github.com/shopsys/project-base/commit/2f97c8a2e6dffe8ee4fde09344be7ce719696304) for example

- use strict comparison in category panel template to prevent errors ([#1782](https://github.com/shopsys/shopsys/pull/1782))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/06f09d87deecc9547430394e751fb84273a218a1) to update your project

- fix validation of addresses in customer section ([#1797](https://github.com/shopsys/shopsys/pull/1797))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/b0b161ac61b7f3ff49a70cd6e646b8f2857af744) to update you

- fix symfony `dump()` function ([#1745](https://github.com/shopsys/shopsys/pull/1745))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/f1a4c5036a1f3eab202524d2cdc6fa29851468a8) to update your project
    
- add compatibility for edge ([#1804](https://github.com/shopsys/shopsys/pull/1804))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/aa314e15b700a8af0c2cc097defcdca46acceeb7) to update your project
    
- add protection before double submit forms ([#1800](https://github.com/shopsys/shopsys/pull/1800))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/702146d7779f5ce549af65895135c7f85ab39dc6) to update your project

- refactored `SelectToggle` component ([#1803](https://github.com/shopsys/shopsys/pull/1803))
    - `ToggleOption` js class has been removed, update your code appropriately

- remove deprecated methods from your project ([#1801](https://github.com/shopsys/shopsys/pull/1801))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/a9dd272a3831beadecfc5da9f061898a3a9e5205) to update your project

- unify config files extensions to yaml ([#1814](https://github.com/shopsys/shopsys/pull/1814)
    - see [project-base diff](https://github.com/shopsys/project-base/commit/0861abcb9e9445d62f4f9461380b1314c9d3ece8) to update your project

- remove subscription newsletter form from error pages ([#1819](https://github.com/shopsys/shopsys/pull/1819))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/69c1b83b7a511c7725d57581915ddb2dea2e0183) to update your project

- fix validation of parameters uniqueness ([#1822](https://github.com/shopsys/shopsys/pull/1822))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/8d339e65ab748d36ecbf70c9204be54ac6d24772) to update your project

- split error page templates to allow to render new 410 error response and remove friendly url slug after remove category or brand ([#1829](https://github.com/shopsys/shopsys/pull/1829))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/712311632006e83f9dfe5ec2924e6a9c512636bd) to update your project
    - exception `ProductNotFoundException` has new parent. The original parent `NotFoundHttpException` was replaced by `GoneHttpException`
    - following methods has changed their interface, update your usages accordingly:
        - `BrandFacade::deleteById()`
            ```diff
            - public function deleteById($brandId)
            + public function deleteById(int $brandId): void
            ```

- fix untranslated texts in admin ([#1841](https://github.com/shopsys/shopsys/pull/1841))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/f00b2188e0d31f2ef9f2b3b83b4ceb65ee1954d2) to update your project

- update selling to for product data fixture in order to fix failing tests ([#1677](https://github.com/shopsys/shopsys/pull/1677))
    - see [project-base diff](https://github.com/shopsys/project-base/commit/fc1fc0a0ed1ce4681b8cedac1cf7e487353eb0de) to update your project

### Tools

- apply coding standards checks on your `app` folder ([#1306](https://github.com/shopsys/shopsys/pull/1306))
  - run `php phing standards-fix` and fix possible violations that need to be fixed manually
  - see [project-base diff](https://github.com/shopsys/project-base/commit/3126182f41a680f3b6d2565ccae716491b5e8b09) to update your project

- if you want to add stylelint rules to check style coding standards ([#1511](https://github.com/shopsys/shopsys/pull/1511))
    -  add new file [.stylelintignore](https://github.com/shopsys/shopsys/blob/master/project-base/.stylelintignore)
    -  add new file [.stylelintrc](https://github.com/shopsys/shopsys/blob/master/project-base/.stylelintrc)
    - update `package.json` and add this to `devDependencies`
        ```diff
            "stylelint": "^11.1.1",
        ```
    - to fix all your less files in command line by command `php phing stylelint-fix`

- make email templates editable on separate page ([#1828](https://github.com/shopsys/shopsys/pull/1828))
    - if you have your own mail templates implemented, please refer the [Adding a New Email Template](https://docs.shopsys.com/en/latest/cookbook/adding-a-new-email-template) cookbook to add your templates into the administration
    - these methods have changed so you might want to update usages in your application:
        - `MailController::__construct()`
            ```diff
                public function __construct(
            -       ResetPasswordMail $resetPasswordMail,
            -       OrderMail $orderMail,
            -       RegistrationMail $registrationMail,
                    AdminDomainTabsFacade $adminDomainTabsFacade,
                    MailTemplateFacade $mailTemplateFacade,
                    MailSettingFacade $mailSettingFacade,
            -       OrderStatusFacade $orderStatusFacade,
            -       PersonalDataAccessMail $personalDataAccessMail,
            -       PersonalDataExportMail $personalDataExportMail
            +       BreadcrumbOverrider $breadcrumbOverrider,
            +       MailTemplateGridFactory $mailTemplateGridFactory,
            +       MailTemplateConfiguration $mailTemplateConfiguration,
            +       MailTemplateDataFactory $mailTemplateDataFactory
                )
            ```
        - `MailTemplateFacade::__construct()`
            ```diff
                public function __construct(
                    EntityManagerInterface $em,
                    MailTemplateRepository $mailTemplateRepository,
            -       OrderStatusRepository $orderStatusRepository,
                    Domain $domain,
                    UploadedFileFacade $uploadedFileFacade,
                    MailTemplateFactoryInterface $mailTemplateFactory,
                    MailTemplateDataFactoryInterface $mailTemplateDataFactory,
                    MailTemplateAttachmentFilepathProvider $mailTemplateAttachmentFilepathProvider
                     )
            ```

    - these methods were removed
        - `MailController::getOrderStatusVariablesLabels()` (labels are managed with `MailTemplateVariables` class)
        - `MailController::getRegistrationVariablesLabel()` (labels are managed with `MailTemplateVariables` class)
        - `MailController::getResetPasswordVariablesLabels()` (labels are managed with `MailTemplateVariables` class)
        - `MailController::getPersonalDataAccessVariablesLabels()` (labels are managed with `MailTemplateVariables` class)
        - `MailController::getPersonalExportVariablesLabels()` (labels are managed with `MailTemplateVariables` class)
        - `MailController::getTemplateParameters()` (template parameters are managed with `MailTemplateVariables` class)
        - `RegistrationMail::getTemplateVariables()` (variables are managed with `MailTemplateVariables` class)
        - `ResetPasswordMail::getBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `ResetPasswordMail::getSubjectVariables()` (variables are managed with `MailTemplateVariables` class)
        - `ResetPasswordMail::getRequiredBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `ResetPasswordMail::getRequiredSubjectVariables()` (variables are managed with `MailTemplateVariables` class)
        - `MailTemplateFacade::getOrderStatusMailTemplatesIndexedByOrderStatusId()`
        - `MailTemplateFacade::getFilteredOrderStatusMailTemplatesIndexedByOrderStatusId()`
        - `MailTemplateFacade::saveMailTemplatesData()`
        - `MailTemplateFacade::getAllMailTemplatesDataByDomainId()`
        - `OrderMail::getTemplateVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataAccessMail::getBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataAccessMail::getSubjectVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataAccessMail::getRequiredBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataAccessMail::getRequiredSubjectVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataExportMail::getBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataExportMail::getSubjectVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataExportMail::getRequiredBodyVariables()` (variables are managed with `MailTemplateVariables` class)
        - `PersonalDataExportMail::getRequiredSubjectVariables()` (variables are managed with `MailTemplateVariables` class)

    - these classes were removed so you might need to update your application appropriately:
        - `Shopsys\FrameworkBundle\Form\Admin\Mail\AllMailTemplatesFormType` (it's no longer necessary to manage all mail templates at once)
        - `Shopsys\FrameworkBundle\Model\Mail\AllMailTemplatesData` (it's no longer necessary to manage all mail templates at once)
        - `Shopsys\FrameworkBundle\Model\Mail\DummyMailType`
        - `Shopsys\FrameworkBundle\Model\Mail\MailTypeInterface` (no classes now implement this interface)
       
    - these Twig templates were removed
        - `@ShopsysFramework/Admin/Content/Mail/template.html.twig`

### Removed deprecations

In this major version were removed deprecated features ([#1801](https://github.com/shopsys/shopsys/pull/1801)).
If you followed all steps in previous upgrades and resolved all deprecations, you shouldn't be worried about this.
The list here can help you quickly resolve problems with any deprecations left in your application.

following methods has changed their interface, update your usages accordingly:
- `AdvertDataFactory::__construct()`
    ```diff
    -   public function __construct(?ImageFacade $imageFacade = null)
    +   public function __construct(ImageFacade $imageFacade)
    ```
- `BrandDataFactory::__construct()`
    ```diff
        public function __construct(
            FriendlyUrlFacade $friendlyUrlFacade,
            BrandFacade $brandFacade,
            Domain $domain,
    -       ?ImageFacade $imageFacade = null
    +       ImageFacade $imageFacade
        )
    ```
- `CategoryDataFactory::__construct()`
    ```diff
        public function __construct(
            CategoryRepository $categoryRepository,
            FriendlyUrlFacade $friendlyUrlFacade,
            PluginCrudExtensionFacade $pluginCrudExtensionFacade,
            Domain $domain,
    -       ?ImageFacade $imageFacade = null
    +       ImageFacade $imageFacade
        )
    ```
- `CronFacade::__construct()`
    ```diff
        public function __construct(
            Logger $logger,
            CronConfig $cronConfig,
            CronModuleFacade $cronModuleFacade,
            Mailer $mailer,
    -       ?CronModuleExecutor $cronModuleExecutor = null
    +       CronModuleExecutor $cronModuleExecutor
        )
  ```
- `DateTimeFormatter::__construct()`
    ```diff
        public function __construct(
            DateTimeFormatPatternRepository $customDateTimeFormatPatternRepository,
    -       ?DisplayTimeZoneProviderInterface $displayTimeZoneProvider = null
    +       DisplayTimeZoneProviderInterface $displayTimeZoneProvider
        )
    ```
- `ErrorPagesFacade::__construct()`
    ```diff
        public function __construct(
            $errorPagesDir,
            Domain $domain,
            DomainRouterFactory $domainRouterFactory,
            Filesystem $filesystem,
    -       ?ErrorIdProvider $errorIdProvider = null
    +       ErrorIdProvider $errorIdProvider
        )
    ```
- `ImageUploadType::__construct()`
    ```diff
        public function __construct(
            ImageFacade $imageFacade,
            ImagesIdsToImagesTransformer $imagesIdsToImagesTransformer,
    -       ?ImageConfig $imageConfig = null
    +       ImageConfig $imageConfig
        )
    ```
- `LocalizationListener::__construct()`
    ```diff
        public function __construct(
            Domain $domain,
            Localization $localization,
    -       ?AdministrationFacade $administrationFacade = null
    +       AdministrationFacade $administrationFacade
        )
    ```
- `NumberFormatterExtension::__construct()`
    ```diff
        public function __construct(
            Localization $localization,
            NumberFormatRepositoryInterface $numberFormatRepository,
    +       ?AdministrationFacade $administrationFacade = null
    -       AdministrationFacade $administrationFacade
        )
    ```
- `PaymentDataFactory::__construct()`
    ```diff
        public function __construct(
            PaymentFacade $paymentFacade,
            VatFacade $vatFacade,
            Domain $domain,
    -       ?ImageFacade $imageFacade = null
    +       ImageFacade $imageFacade
        )
    ```
- `PriceExtension::__construct()`
    ```diff
        public function __construct(
            CurrencyFacade $currencyFacade,
            Domain $domain,
            Localization $localization,
            NumberFormatRepositoryInterface $numberFormatRepository,
            CurrencyRepositoryInterface $intlCurrencyRepository,
    -       ?CurrencyFormatterFactory $currencyFormatterFactory = null
    +       CurrencyFormatterFactory $currencyFormatterFactory
       )
    ```
- `ProductInputPriceRecalculator::__construct()`
    ```diff
        public function __construct(
            BasePriceCalculation $basePriceCalculation,
            InputPriceCalculation $inputPriceCalculation,
    -       ?CurrencyFacade $currencyFacade = null
    +       CurrencyFacade $currencyFacade
        )
    ```
- `ProductPriceCalculation::__construct()`
    ```diff
        public function __construct(
            BasePriceCalculation $basePriceCalculation,
            PricingSetting $pricingSetting,
            ProductManualInputPriceRepository $productManualInputPriceRepository,
            ProductRepository $productRepository,
    -       ?CurrencyFacade $currencyFacade = null
    +       CurrencyFacade $currencyFacade
        )
    ```
- `PromoCodeController::__construct()`
    ```diff
        public function __construct(
            PromoCodeFacade $promoCodeFacade,
    -       PromoCodeInlineEdit $promoCodeInlineEdit,
            AdministratorGridFacade $administratorGridFacade,
    -       ?PromoCodeDataFactoryInterface $promoCodeDataFactory = null,
    +       PromoCodeDataFactoryInterface $promoCodeDataFactory,
    -       ?PromoCodeGridFactory $promoCodeGridFactory = null,
    +       PromoCodeGridFactory $promoCodeGridFactory,
    -       ?BreadcrumbOverrider $breadcrumbOverrider = null,
    +       BreadcrumbOverrider $breadcrumbOverrider
    -       bool $useInlineEditation = true
        )
    ```
- `QuantifiedProductPriceCalculation::__construct()`
    ```diff
        public function __construct(
            ProductPriceCalculationForCustomerUser $productPriceCalculationForCustomerUser,
    -       Rounding $rounding,
            PriceCalculation $priceCalculation
        )
    ```
- `QueryBuilderExtender::__construct()`
    ```diff
    -   public function __construct(?EntityNameResolver $entityNameResolver = null)
    +   public function __construct(EntityNameResolver $entityNameResolver)
    ```
- `ShopsysFrameworkDataCollector::__construct()`
    ```diff
        public function __construct(
            Domain $domain,
    -       ?DisplayTimeZoneProviderInterface $displayTimeZoneProvider = null
    +       DisplayTimeZoneProviderInterface $displayTimeZoneProvider
        )
    ```
- `SliderItemDataFactory::__construct()`
    ```diff
    -   public function __construct(?ImageFacade $imageFacade = null)
    +   public function __construct(ImageFacade $imageFacade)
    ```
- `TransportDataFactory::__construct()`
    ```diff
        public function __construct(
            TransportFacade $transportFacade,
            VatFacade $vatFacade,
            Domain $domain,
    -       ?ImageFacade $imageFacade = null
    +       ImageFacade $imageFacade
        )
    ```
- `VatController::__construct()`
    ```diff
        public function __construct(
            VatFacade $vatFacade,
    -       PricingSetting $pricingSetting,
            VatInlineEdit $vatInlineEdit,
            ConfirmDeleteResponseFactory $confirmDeleteResponseFactory,
            AdminDomainTabsFacade $adminDomainTabsFacade
        )
    ```

- `ImageFacade::uploadImage`
    ```diff
    -   public function uploadImage($entity, $temporaryFilenames, $type)
    +   protected function uploadImage($entity, $temporaryFilenames, $type): void
    ```
- `ImageFacade::saveImageOrdering`
    ```diff
    -   public function saveImageOrdering($orderedImages)
    +   protected function saveImageOrdering($orderedImages): void
    ```
- `ImageFacade::uploadImages`
    ```diff
    -   public function uploadImages($entity, $temporaryFilenames, $type)
    +   protected function uploadImages($entity, $temporaryFilenames, $type): void
    ```
- `ImageFacade::deleteImages`
    ```diff
    -   public function deleteImages($entity, array $images)
    +   protected function deleteImages($entity, array $images): void
    ```

following methods were removed. Use corresponding replacement instead:
- `BasePriceCalculation::calculateBasePrice()` was removed, use `BasePriceCalculation::calculateBasePriceRoundedByCurrency()` instead
- `BasePriceCalculation::applyCoefficients()` was removed as it was used only in tests. Use your implementation if you need the functionality
- `BasePriceCalculation::getBasePriceWithVat()` was removed, use `BasePriceCalculation::getBasePriceWithVatRoundedCurrency()` instead
- `CronFacade::runModulesForInstance()` was removed, use `CronFacade::runModules()` instead
- `CronFacade::runModule()` was removed, use `CronFacade::runSingleModule()` instead
- `CurrencyFormatterFactory::create()` was removed, use `CurrencyFormatterFactory::createByLocaleAndCurrency()`
- `Domain::getAllIdsExcludingFirstDomain()` was removed. Use your implementation if you need the functionality
- `LocalizationListener::isAdminRequest()` was removed, use `Shopsys\FrameworkBundle\Model\Administration\AdministrationFacade::inInAdmin()` instead
- `PriceExtension::getCurrencyFormatter()` was removed, use `CurrencyFormatterFactory::createByLocaleAndCurrency()`
- `PricingSetting::getRoundingType()` was removed, rounding type can be set per Currency
- `PricingSetting::setRoundingType()` was removed, rounding type can be set per Currency
- `PricingSetting::getRoundingTypes()` was removed, rounding type can be set per Currency
- `Rounding::roundPriceWithVat()` was removed, use `Rounding::roundPriceWithVatByCurrency()`
- `QuantifiedProductDiscountCalculation::calculateDiscount()` was removed, use `QuantifiedProductDiscountCalculation::calculateDiscountRoundedByCurrency()`
- `QuantifiedProductDiscountCalculation::calculateDiscounts()` was removed, use `QuantifiedProductDiscountCalculation::calculateDiscountsRoundedByCurrency()`
- `RedisFacade::hasAnyKey()` was removed. Use your implementation if you need the functionality

following classes were removed and should not be used anywhere in your project:
- `Shopsys\FrameworkBundle\Model\Cart\Exception\CartIsEmptyException` was removed. Use your implementation if you need
- `Shopsys\FrameworkBundle\Model\Localization\CustomDateTimeFormatterFactory` was removed. `DateTimeFormatter` should be created directly by DI container
- `Shopsys\FrameworkBundle\Model\Order\PromoCode\Grid\PromoCodeInlineEdit` was removed. `PromoCodeGridFactory` should be used instead
- `Shopsys\FrameworkBundle\Component\Doctrine\Cache\RedisCacheFactory` was removed. Use setter injection of the Redis instance in DIC configuration of the `RedisCache` service instead

following constants were removed. Create your own constant if needed
- `CronFacade::TIMEOUT_SECONDS = 240`
- `CurrencyFormatterFactory::MINIMUM_FRACTION_DIGITS = 2`
- `PriceExtension::MINIMUM_FRACTION_DIGITS = 2`
- `PriceExtension::MAXIMUM_FRACTION_DIGITS = 10`
- `PricingSetting::ROUNDING_TYPE = 'roundingType'`
- `PricingSetting::ROUNDING_TYPE_HUNDREDTHS = 1`
- `PricingSetting::ROUNDING_TYPE_FIFTIES = 2`
- `PricingSetting::ROUNDING_TYPE_INTEGER = 3`

following properties were removed from Phing
- property `is-multidomain` was removed, see `domains-info-load` target instead
- property `translations.dump.locales` was removed, see `domains-info-load` target instead

following services are no longer registered. Use corresponding replacement instead
- `DateTimeFormatter`, use `DateTimeFormatterInterface` instead

following form type options were removed
- `PromoCodeFormType` no longer has option `isInlineEdit`

[shopsys/framework]: https://github.com/shopsys/framework
