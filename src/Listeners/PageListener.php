<?php
/**
 * Created by Jasper Vriends
 * www.vriends.co - GitHub @jaspervriends
 */

namespace JasperVriends\FlarumSeo\Listeners;

// FlarumSEO classes
use JasperVriends\FlarumSeo\Managers\Discussion;
use JasperVriends\FlarumSeo\Managers\Profile;
use JasperVriends\FlarumSeo\Managers\QADiscussion;
use JasperVriends\FlarumSeo\Managers\Tag;

// Flarum classes
use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Discussion\DiscussionRepository;
use Flarum\User\UserRepository;

// Laravel classes
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class PageListener
 * @package JasperVriends\FlarumSeo
 */
class PageListener
{
    // Config
    protected $config;
    protected $applicationUrl;

    // Settings
    protected $settings;
    protected $discussionRepository;
    protected $userRepository;
    protected $enabled_extensions;

    // Document
    protected $flarumDocument;

    // Server request data
    protected $serverRequest;

    private $requestType = null;

    private $canonicalUrl = null;

    // Schema.org LD JSON
    protected $schemaArray = [
        '@context' => 'http://schema.org',
        '@type' => 'WebPage'
    ];

    protected $schemaBreadcrumb = [];

    // Meta data with property tags
    protected $metaProperty;

    protected $discussionType = 1; // Special Google results as default, check check readme for different results

    /**
     * PageListener constructor.
     *
     * @param SettingsRepositoryInterface $settings
     * @param DiscussionRepository $discussionRepository
     * @param UserRepository $userRepository
     */
    public function __construct(SettingsRepositoryInterface $settings, DiscussionRepository $discussionRepository, UserRepository $userRepository)
    {
        // Get Flarum settings
        $this->settings = $settings;

        // Get Discussion Repository
        $this->discussionRepository = $discussionRepository;

        // Get User Repository
        $this->userRepository = $userRepository;

        // Get Flarum config
        $this->config = app('flarum.config');

        // Set forum base URL
        $this->applicationUrl = $this->config['url']; // Set site url

        // List enabled extensions
        $this->enabled_extensions = json_decode($this->settings->get("extensions_enabled"), true);

        // Fancy SEO question-answer?
        $this->discussionType = $this->settings->get("disable_fancy_discussion_seo") === null ? 2 : 1;

        // Settings debug settings: var_dump($this->settings->all());exit;
    }

    /**
     * Get current Flarum document and current Server Request
     *
     * @param Document $flarumDocument
     * @param ServerRequestInterface $serverRequestInterface
     */
    public function __invoke(Document $flarumDocument, ServerRequestInterface $serverRequestInterface)
    {
        // Flarum document
        $this->flarumDocument = $flarumDocument;

        // Current Server Request
        $this->serverRequest = $serverRequestInterface;

        // Check out type of page
        $this->determine();

        // Default site tags
        $this->setSiteTags();

        // Finish process
        $this->finish();
    }

    /**
     * Determine the current page type
     */
    private function determine()
    {
        // Request type
        $this->requestType = substr($this->serverRequest->getUri()->getPath(), 1, 2);

        // Query params
        $queryParams = $this->serverRequest->getQueryParams();

        // User profile page
        if($this->requestType === 'u/') {
            new Profile($this, $this->userRepository, isset($queryParams['username']) ? $queryParams['username'] : false);
        }

        // Tag page
        else if($this->requestType === 't/') {
            new Tag($this,isset($queryParams['slug']) ? $queryParams['slug'] : false);
        }

        // Default SEO (no fancy QA layout)
        else if($this->requestType === 'd/' && $this->discussionType === 1) {
            new Discussion($this, $this->discussionRepository, isset($queryParams['id']) ? $queryParams['id'] : false);
        }

        // QuestionAnswer page
        else if($this->requestType === 'd/' && $this->discussionType === 2) {
            new QADiscussion($this, $this->discussionRepository, isset($queryParams['id']) ? $queryParams['id'] : false);
        }

        // Home page
        else if($this->requestType === "") {
            $this->setDescription($this->settings->get('forum_description'));
        }
    }

    /**
     * Default site meta tags
     * Available for all webpages
     */
    private function setSiteTags()
    {
        $applicationName = $this->settings->get('forum_title');
        $applicationDescription = $this->settings->get('forum_description');
        $applicationFavicon = $this->settings->get('favicon_path');


        $this
            // Add application name
            ->setMetaTag('application-name', $applicationName)
            ->setMetaPropertyTag('og:site_name', $applicationName)

            // Robots, follow please! :)
            ->setMetaTag('robots', 'index, follow')

            // Twitter card
            ->setMetaTag('twitter:card', 'summary');

        // Image, using the favicon for now
        if($applicationFavicon !== null)
        {
            $this->setMetaPropertyTag('og:image', $this->applicationUrl . '/assets/' . $applicationFavicon);
            $this->setMetaTag('twitter:image', $this->applicationUrl . '/assets/' . $applicationFavicon);
            $this->setSchemaJson('image', $this->applicationUrl . '/assets/' . $applicationFavicon);
        }

        // Add application information
        $this->setSchemaJson('publisher', [
            "@type" => "Organization",
            "name" => $applicationName,
            "url" => $this->applicationUrl,
            "description" => $applicationDescription
        ]);
    }

    /**
     * Finish process and output meta property tags & Schema.org json
     */
    private function finish()
    {

        // Add OG meta property tags
        $this->flarumDocument->head[] = implode("\n", array_merge($this->flarumDocument->head, array_map(function ($content, $name) {
            return '<meta property="'.e($name).'" content="'.e($content).'">';
        }, $this->metaProperty, array_keys($this->metaProperty))));

        // Add canonical url
        if($this->canonicalUrl !== null)
        {
            $this->flarumDocument->head[] = '<link rel="canonical" href="'. $this->canonicalUrl .'">';
        }

        // Add schema.org json
        $this->flarumDocument->head[] = $this->writeSchemesOrgJson();
    }

    /**
     * Schema.org json
     */
    private function writeSchemesOrgJson()
    {
        $show = [];
        $show[] = $this->schemaArray;

        if(count($this->schemaBreadcrumb) > 0) {
            $show[] = $this->schemaBreadcrumb;
        }

        return '<script type="application/ld+json">' . json_encode($show, true) . '</script>';
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setMetaPropertyTag($key, $value)
    {
        $this->metaProperty[$key] = $value;

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setMetaTag($key, $value)
    {
        $this->flarumDocument->meta[$key] = $value;

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setSchemaJson($key, $value)
    {
        $this->schemaArray[$key] = $value;

        return $this;
    }

    /**
     * @param $discussion
     */
    public function setSchemaBreadcrumb($discussion)
    {
        $tags = $discussion->getAttribute("tags");
        $list = [];

        // Foreach tags
        $number = 0;
        foreach ($tags as $tag)
        {
            $number++;
            $list = [
                '@type' => 'ListItem',
                'name' => $tag->getAttribute('name'),
                'item' => $this->applicationUrl . '/t/' . $tag->getAttribute('slug'),
                'position' => $number
            ];
        }

        $this->schemaBreadcrumb = [
            "@context" => "http://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => $list
        ];
    }

    /**
     * @param $name
     * @return bool
     */
    public function extensionEnabled($name)
    {
        return in_array($name, $this->enabled_extensions);
    }

    /**
     * Current page URL
     *
     * @param $path
     * @return PageListener
     */
    public function setUrl($path)
    {
        $this->setMetaTag('twitter:url', $this->applicationUrl . $path);
        $this->setMetaPropertyTag('og:url', $this->applicationUrl . $path);
        $this->setSchemaJson("url", $this->applicationUrl . $path);

        return $this;
    }

    /**
     * Set canonical url
     *
     * @param $path
     * @return PageListener
     */
    public function setCanonicalUrl($path)
    {
        $this->canonicalUrl = $this->applicationUrl . $path;

        return $this;
    }

    /**
     * @param $path
     * @return string
     */
    public function getApplicationPath($path)
    {
        return $this->applicationUrl . $path;
    }

    /**
     * Get user data
     *
     * @param $userId
     * @return mixed
     */
    public function getUser($userId)
    {
        return $this->userRepository->findOrFail($userId);
    }

    /**
     * Set title
     *
     * @param $title
     * @return PageListener
     */
    public function setTitle($title)
    {
        $this
            ->setMetaPropertyTag('og:title', $title)
            ->setMetaTag('twitter:title', $title);

        return $this;
    }

    /**
     * Set description
     *
     * @param $description
     * @return PageListener
     */
    public function setDescription($description)
    {
        $description = trim(preg_replace('/\s+/', ' ', substr($description, 0, 157))) . (strlen($description) > 157 ? '...' : '');

        $this
            ->setMetaPropertyTag('og:description', $description)

            ->setMetaTag('description', $description)
            ->setMetaTag('twitter:description', $description)
            ->setSchemaJson("description", $description);

        if($this->requestType === 'd/')
        {
            $this->setSchemaJson("headline", $description);
        }

        return $this;
    }

    /**
     * Set published on
     *
     * @param $published
     * @return PageListener
     */
    public function setPublishedOn($published)
    {
        $date = (new \DateTime($published))->format("c");

        $this
            ->setMetaTag('article:published_time', $date)
            ->setSchemaJson('datePublished', $date);

        return $this;
    }

    /**
     * Set updated time
     * Only used when a discussion has newer posts
     *
     * @param $updated
     * @return PageListener
     */
    public function setUpdatedOn($updated)
    {
        $date = (new \DateTime($updated))->format("c");

        $this
            ->setMetaTag('article:updated_time', $date)
            ->setSchemaJson('dateModified', $date);

        return $this;
    }
}