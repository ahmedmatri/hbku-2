namespace Drupal\hbku_misc\Plugin\Block;

use Drupal\block\Entity\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Url;
use Drupal\views\Views;
use Drupal\Core\Form\FormState;
use Drupal\Core\Cache\Cache;

/**
 * Provides a 'HeaderBlock' block.
 *
 * @Block(
 *  id = "header_block",
 *  admin_label = @Translation("Header block"),
 * )
 */
class HeaderBlock extends BlockBase {


  /**
   * {@inheritdoc}
   */
  public function build() {

    $build = [
      '#theme' => 'headerblock',
      '#content' => [],
    ];

    $current_group = \Drupal::service('hbku_group.helper')
      ->getActiveGroup();
    // Get acount menu
    $build['#content'] ['user_menu'] = $this->_getMenuItems('user-login');
    $build['#content']['language_switcher'] = $this->_get_language_switcher_block();
    $build['#content']['main_menu'] = \Drupal::service('hbku_misc.helper')
      ->load_menu_tree($current_group ? 'main-' . $current_group->id() : 'main');

    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      $build['#content'] ['class'] =
        ($node->hasField('field_spotlight') && !$node->field_spotlight->isEmpty() or $node->bundle() == 'homepage') ?
          TRUE : 'noImageBanner';
    }
    elseif ($vid = \Drupal::routeMatch()->getParameter('view_id')) {
      if ($current_group = \Drupal::service('hbku_group.helper')
        ->getActiveGroup()) {
        $spotlight = \Drupal::state()
          ->get($vid . '-' . $current_group->id()) ?: NULL;
      }
      else {
        $spotlight = \Drupal::state()->get($vid . '-listing');
      }
      $build['#content'] ['class'] = $spotlight ? TRUE : 'noImageBanner';

    }
    else {
      $build['#content'] ['class'] = 'noImageBanner';
    }
    return $build;
  }


  /**
   * @return mixed
   */
  function _get_language_switcher_block() {
    $prefixes = \Drupal::config('language.negotiation')->get('url.prefixes');
    $languages = \Drupal::languageManager()->getLanguages();
    $current_node = \Drupal::routeMatch()->getParameter('node');
    $render['#current_lang'] = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    foreach ($languages as $key => $language) {
      if ($current_node) {
        //      <entity.node.canonical>
        if ($current_node->hasTranslation($key)) {
          $render['#links'][$key]['url'] = $current_node->getTranslation($key)
            ->toLink()
            ->getUrl()
            ->toString();
        }
        else {
          if ($current_node->getType()) {
            $url = Url::fromRoute('entity.node.canonical', [
              'node' => $current_node->id(),
              'language' => $key,
            ]);
          }
          else {
            $url = Url::fromRoute('custom_front.language_messenger', [
              'node' => $current_node->id(),
            ]);
          }

          $render['#links'][$key]['url'] = $url->toString();
        }
      }
      else {
        //        <front>
        $render['#links'][$key]['url'] = '/' . $prefixes[$key];
      }
      $render['#links'][$key]['name'] = $language->getName();
    }
    return $render;
  }

  /**
   * Get search form block
   *
   * @return void
   */
  protected function _getSearchForm() {
    $view = Views::getView('search');
    if (!empty($view)) {
      $view->setDisplay(NULL);
      $view->initHandlers();
      $form_state = (new FormState())
        ->setStorage([
          'view' => $view,
          'display' => &$view->display_handler->display,
          'rerender' => TRUE,
        ])
        ->setMethod('get')
        ->setAlwaysProcess()
        ->disableRedirect();
      $form_state->set('rerender', NULL);
      $form = \Drupal::formBuilder()
        ->buildForm('\Drupal\views\Form\ViewsExposedForm', $form_state);

      $form['search_api_fulltext']['#attributes']['class'] = [
        'searchbox__input',
        'search-input',
        'form-autocomplete',
        'ui-autocomplete-input',
      ];
      unset($form['search_api_fulltext']['#attributes']['class']['.ui-autocomplete-loading']);

      $form['search_api_fulltext']['#attributes']['aria-label'] = [
        t('Tapez votre recherche ici...'),
      ];

      $form['search_api_fulltext']['#attributes']['required'] = [
        'true',
      ];
      unset($form['elements']['search_api_fulltext']['#theme_wrappers']);
      unset($form['search_api_fulltext']['#theme_wrappers']);
      $form['search_api_fulltext']['#attributes']['placeholder'] = t('Tapez votre recherche ici...');
      $form['search_api_fulltext']['#attributes']['location'] = 'header';
      $form['search_api_fulltext']['#attributes']['autocomplete'][] = "off";
      unset($form['search_api_fulltext']['#value']);

      $landing_url = \Drupal::service('custom_landing.service')
        ->getLandingNodeUrlByViewsDisplayName('search:wide');

      $form['#action'] = $landing_url;

      return $form;
    }
    return [];
  }

  /**
   * @return array|string[]
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @see https://drupal.stackexchange.com/questions/199527/how-do-i-correctly-setup-caching-for-my-custom-block-showing-content-depending-o
   */
  public function getCacheTags() {
    $cache_tags = parent::getCacheTags();
    $cache_tags[] = "config:system.menu.main";
    $cache_tags[] = "block_content:1";
    return $cache_tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), []);
  }

  /**
   * returns a built menu tree
   *
   * @param string $menu_name
   * @param array $parameters
   * @param [type] $root
   * @param string $preview_mode
   *
   * @return array
   */
  function _getMenuItems($menu_name, $parameters = NULL, $root = NULL, $preview_mode = NULL) {
    $menuTree = \Drupal::menuTree();
    if (empty($parameters)) {
      $parameters = $menuTree->getCurrentRouteMenuTreeParameters($menu_name);
    }

    if (!empty($root)) {
      $parameters->setRoot($root);
      $parameters->excludeRoot();
    }


    $tree = $menuTree->load($menu_name, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      //      ['callable' => 'custom_menu.menu_tree_manipulators:filterTreeByCurrentLanguage'],
    ];
    $tree = $menuTree->transform($tree, $manipulators);
    $build = $menuTree->build($tree);

    if (!empty($build['#items'])) {
      foreach ($build['#items'] as $k => $item) {
        $menu_icon = $item['url']->getOption('menu_icon');
        if (!empty($menu_icon)) {
          if (!empty($menu_icon['icon_class'])) {
            $build['#items'][$k]['menu_icon_class'] = $menu_icon['icon_class'];
          }
          elseif (!empty($menu_icon['icon_file_url'])) {
            $build['#items'][$k]['menu_icon_url'] = $menu_icon['icon_file_url'];
          }
        }

        if (!empty($preview_mode) && $item['original_link']->getRouteName() == 'entity.node.canonical') {
          $nid = $item['original_link']->getRouteParameters()['node'];
          $node = $this->entityTypeManager->getStorage('node')->load($nid);
          if (!empty($node)) {
            $build['#items'][$k]['node'] = $this->entityTypeManager->getViewBuilder('node')
              ->view($node, $preview_mode);
          }
        }
      }
      // @todo set cache informations
      // $build['#cache'] = [
      //   'tags' => [],
      //   'max-age' => Cache::PERMANENT,
      //   'contexts' => [],
      // ];
    }

    return $build;
  }


}
