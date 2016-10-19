'use strict';

import React from 'react';
import ReactDOM    from 'react-dom';
import App        from './../components/app';
import SelfTest    from './../components/routes/selftest';
import routeChecks from './../helpers/common-route-checks';

module.exports = {
    path: '/{locale}/config/self-test',
    preController: [routeChecks.ifTensideNotOkRedirectToInstall, routeChecks.ifUserNotLoggedInRedirectToLogin],
    controller: function(request, routing) {
        ReactDOM.render(<App routing={routing}><SelfTest /></App>, document.getElementById('react-container'));
    }
};
