jQuery(document).ready(function ($) {
    $('#woocommerce_shiptime_connect').on('click', function (e) {
        e.preventDefault();
        var data = {
            action: "oauth_st"
        };
        $.ajax({
            url: ajaxurl,
            type: 'post',
            data: data,
            success: function (response) {
                var data = $.parseJSON(response);
                if($('#woocommerce_shiptime_enabled').is(":checked")) {
                   	if(data['apiSecret']){
						var url = data['apiUrl']+'?storeUrl='+data['storeUrl']+'&apiKey='+data['apiSecret']+'&platform='+data['platform']; // https://shiptimev3.appspaces.ca/directapp
                  		var redirect = "_blank";
					}
					else{
						 var url = location.reload();
                         var redirect = "_self";
					}
                   
                }
                else
                {
                    var url = location.reload();
                    var redirect = "_self";
                }

                if (response.error) {
                    console.log('error');
                } else {
                    window.open(url, redirect);
                }
            }
        });
    });

    if($('#woocommerce_shiptime_enabled').is(":checked")) {
        $("#woocommerce_shiptime_connect").val("Connect");
    }
    else{
        $("#woocommerce_shiptime_connect").val("Disconnect");
    }
});