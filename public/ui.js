$(document).ready(function() {

    function updateTotal() {
        var totalPrice = 0.0;
        $("input.price-calc").each(function() {
            totalPrice += parseFloat($(this).data("price")) * parseInt($(this).val() || 0);
        });
        totalPrice += parseFloat($("input#donation").val());
        var launchTicketQty = howManyLaunchTickets();
        var tourTicketQty = parseInt($("input#tour-qty").val());
        if (tourTicketQty > 0) {
            totalPrice -= 10 * Math.min(launchTicketQty, tourTicketQty);
        }
        $("#cart-total").text(totalPrice.toFixed(2).toString());
    }
    $("input.price-calc").on("propertychange change click keyup input paste", function(e) {
        updateTotal();
    });

    $("input.monetary").on("focus", function(e) {
        if ($(this).val() == "0.00") {
            $(this).val("");
        }
    });

    $("input.monetary").on("change blur", function(e) {
        if ($(this).val() == "") {
            $(this).val("0.00");
        }
        $(this).val(parseFloat($(this).val()).toFixed(2));
        updateTotal();
    });

    $("input.uppercase").on("propertychange change click keyup input paste", function(e) {
        $(this).val($(this).val().toUpperCase());
    });

    $("input#upper-qty, input#standard-qty, input#private-qty, input#tour-qty").on("propertychange change click keyup input paste", function(e) {
        var launchTicketQty = howManyLaunchTickets();
        var tourTicketQty = parseInt($("input#tour-qty").val());
        var discountedTourTicketQty = Math.min(launchTicketQty, tourTicketQty)
        if (discountedTourTicketQty > 0) {
            $("#discount-text").html("<b>Tour + Launch Discount:</b> $" + discountedTourTicketQty * 10);
            $("#discount-text").show();
        } else {
            $("#discount-text").hide();
        }
    });

    $("input#standard-qty, input#tour-qty").on("propertychange change click keyup input paste", function(e) {
        if (parseInt($(this).val()) > 0) {
            $(this).parents(".form-group").next(".form-group").slideDown({
                start: function () {
                    $(this).css({
                        display: "flex"
                    })
                }
            });
            $(this).parents(".form-group").next(".form-group").find("select").prop("required", true);
        } else {
            $(this).parents(".form-group").next(".form-group").slideUp();
            $(this).parents(".form-group").next(".form-group").find("select").removeAttr("required");
        }
    });

    $("input[type='number'].price-calc").on("blur", function(e) {
        if ($(this).val() == "") {
            $(this).val("0");
        }
    });

    $(".btn-decrement").click(function() {
        var $input = $(this).parent().next();
        var newValue = parseInt($input.val()) - 1;
        if (newValue >= $input.attr("min")) {
            $input.val(newValue).change();
        }
    });

    $(".btn-increment").click(function() {
        var $input = $(this).parent().prev();
        var newValue = parseInt($input.val()) + 1;
        if ($input.attr("max") == undefined || newValue <= $input.attr("max")) {
            $input.val(newValue).change();
        }
    });

    function linkPrioritySelects($selectGroup) {
        $selectGroup.change(function() {
            $selectGroup.children().prop("disabled", false);
            $selectGroup.each(function() {
                var selectedValue = $(this).val();
                $selectGroup.not(this).children().each(function() {
                    if ($(this).attr("value") == selectedValue && selectedValue != "none" && selectedValue != "") {
                        $(this).prop("disabled", true);
                    }
                });
            });
        });
    }
    linkPrioritySelects($("select.boat-preferences"));
    linkPrioritySelects($("select.tour-preferences"));

    function moreThanOneTicket() {
        return (
            $("input#upper-qty").val() != "0" ||
            $("input#standard-qty").val() != "0" ||
            $("input#private-qty").val() != "0" ||
            $("input#tour-qty").val() != "0"
        );
    }

    function howManyLaunchTickets() {
        return (
            parseInt($("input#upper-qty").val()) +
            parseInt($("input#standard-qty").val()) +
            parseInt($("input#private-qty").val())
        );
    }

    function howManyShirts() {
        return (
            parseInt($("input#shirt-qty-sm").val()) +
            parseInt($("input#shirt-qty-md").val()) +
            parseInt($("input#shirt-qty-lg").val()) +
            parseInt($("input#shirt-qty-xl").val()) +
            parseInt($("input#shirt-qty-xxl").val()) +
            parseInt($("input#shirt-qty-xxxl").val())
        );
    }

    $("form#launch-form").submit(function(e) {

        if (!moreThanOneTicket() && $("#cookie-qty").val() != "0") {
            e.preventDefault();
            alert("Cookies are only available with purchase of at least one launch or tour ticket.");
            return false;
        }

        if (!moreThanOneTicket() && howManyShirts() == 0) {
            e.preventDefault();
            alert("Please choose at least one launch ticket, tour ticket, or t-shirt to continue.");
        }

        if (!moreThanOneTicket() && howManyShirts() > 0) {
            if(!confirm("NOTE: You are ordering one or more shirts without any launch or tour tickets. Star-Fleet Tours does not ship shirts at this time, so continue only if you are able to pick up your shirt(s) with everyone else the day of the tours/launch or you have made arrangements with a Star-Fleet crewmember.")) {
                e.preventDefault();
            }
        }
    })

    $("form.submit-once").submit(function(e){
        if($(this).hasClass("form-submitted")){
            console.log("Double submit prevented.");
            e.preventDefault();
            return;
        }
        $(this).addClass("form-submitted");
    });

    // Improve scrolling to invalid inputs
    var form = $("#launch-form");
    var navbar = $($("nav.navbar")[0]);
    document.addEventListener("invalid", function(e){
        var input = $(e.target);
        var first = form.find(":invalid").filter(":input").first();
        if (input[0] !== first[0]) {
            return true;
        }
        var navbarHeight = navbar.height() + 100;
        var elementOffset = input.offset().top - navbarHeight;
        var pageOffset = window.pageYOffset - navbarHeight;
        if (elementOffset > pageOffset && elementOffset < pageOffset + window.innerHeight) {
            return true;
        }
        $("html,body").scrollTop(elementOffset);
    }, true);
});
