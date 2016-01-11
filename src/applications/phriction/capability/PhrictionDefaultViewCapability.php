<?php

final class PhrictionDefaultViewCapability
  extends PhabricatorPolicyCapability {

  const CAPABILITY = 'phriction.default.view';

  public function getCapabilityName() {
    return pht('Default View Policy');
  }

  public function shouldAllowPublicPolicySetting() {
    return true;
  }

}
