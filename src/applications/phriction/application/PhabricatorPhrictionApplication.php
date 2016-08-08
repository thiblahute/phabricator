<?php

final class PhabricatorPhrictionApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Phriction');
  }

  public function getShortDescription() {
    return pht('Wiki');
  }

  public function getBaseURI() {
    return '/w/';
  }

  public function getIcon() {
    return 'fa-book';
  }

  public function isPinnedByDefault(PhabricatorUser $viewer) {
    return true;
  }

  public function getHelpDocumentationArticles(PhabricatorUser $viewer) {
    return array(
      array(
        'name' => pht('Phriction User Guide'),
        'href' => PhabricatorEnv::getDoclink('Phriction User Guide'),
      ),
    );
  }

  public function getTitleGlyph() {
    return "\xE2\x9A\xA1";
  }

  public function getRemarkupRules() {
    return array(
      new PhrictionRemarkupRule(),
    );
  }

  public function getRoutes() {
    return array(
      // Match "/w/" with slug "/".
      '/w(?P<slug>/)'    => 'PhrictionDocumentController',
      // Match "/w/x/y/z/" with slug "x/y/z/".
      '/w/(?P<slug>.+/)' => 'PhrictionDocumentController',

      '/phriction/' => array(
        '(?:query/(?P<queryKey>[^/]+)/)?' => 'PhrictionListController',

        'history(?P<slug>/)'     => 'PhrictionHistoryController',
        'history/(?P<slug>.+/)'  => 'PhrictionHistoryController',

        'edit/(?:(?P<id>[1-9]\d*)/)?' => 'PhrictionEditController',
        'delete/(?P<id>[1-9]\d*)/'    => 'PhrictionDeleteController',
        'new/'                        => 'PhrictionNewController',
        'move/(?P<id>[1-9]\d*)/' => 'PhrictionMoveController',

        'preview/(?P<slug>.*/)' => 'PhrictionMarkupPreviewController',
        'diff/(?P<id>[1-9]\d*)/' => 'PhrictionDiffController',
      ),
    );
  }

  public function getApplicationOrder() {
    return 0.140;
  }

  protected function getCustomCapabilities() {
    return array(
      PhrictionDefaultViewCapability::CAPABILITY => array(
        'caption' => pht('Default view policy for newly created root pages.'),
        'template' => PhrictionDocumentPHIDType::TYPECONST,
        'capability' => PhabricatorPolicyCapability::CAN_VIEW,
      ),
      PhrictionDefaultEditCapability::CAPABILITY => array(
        'caption' => pht('Default edit policy for newly created root pages.'),
        'template' => PhrictionDocumentPHIDType::TYPECONST,
        'capability' => PhabricatorPolicyCapability::CAN_EDIT,
      ),
    );
  }

  public function getApplicationSearchDocumentTypes() {
    return array(
      PhrictionDocumentPHIDType::TYPECONST,
    );
  }

}
