/* @Description: Service to check whether or not any boxes are checked inside a checkbox model */
FrontEndApp.factory('checkChangedService', function() {
    return {
        checkChanged: function(box, $scope) {
            if(box) $scope.checked++;
			else $scope.checked--;
        }
    };
});
