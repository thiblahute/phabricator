<?php

final class PhrictionDefaultEditCapability
  extends PhabricatorPolicyCapability {

  const CAPABILITY = 'phriction.default.edit';

  public function getCapabilityName() {
    return pht('Default Edit Policy');
  }

  public function shouldAllowPublicPolicySetting() {
    return true;
  }

}
