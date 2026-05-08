(window.webpackJsonp = window.webpackJsonp || []).push([
    [123, 24, 31, 67],
    {
        469: function (t, e, r) {
            "use strict";
            r.r(e);
            var n = r(470),
                o = r.n(n),
                l = r(86),
                c = {
                    name: "LazyImage",
                    props: {
                        alt: { type: String, default: null },
                        title: { type: String, default: null },
                        backgroundColor: { type: String, default: "#d9f4eb" },
                        lazySrc: { type: String, default: null },
                        lazySrcset: { type: String, default: null },
                    },
                    data: function () {
                        return { loading: !0 };
                    },
                    mixins: [l.a],
                    computed: {
                        aspectRatio: function () {
                            return this.width && this.height ? (this.height / this.width) * 100 : null;
                        },
                        style: function () {
                            var style = {};
                            return (
                                this.width && ((style.width = "".concat(this.width, "px")), this.height || (style.height = "".concat(0.66 * this.width, "px"))),
                                this.loading && this.aspectRatio && (style.height = this.height ? "".concat(this.height, "px") : "".concat(this.applyAspectRatio, "px")),
                                style
                            );
                        },
                    },
                    mounted: function () {
                        var t = this,
                            e = function () {
                                (t.loading = !1), (t.$el.style.opacity = 1);
                            };
                        this.$el.addEventListener("load", e),
                            this.$once("hook:destroyed", function () {
                                t.$el.removeEventListener("load", e);
                            }),
                            o()(this.$el, {
                                load: function (t) {
                                    t.src = t.getAttribute("data-src");
                                },
                            }).observe();
                    },
                    methods: {
                        onError: function (t) {
                            t.target.src = this.getImageURL();
                        },
                    },
                },
                d = r(15),
                component = Object(d.a)(
                    c,
                    function () {
                        var t = this;
                        return (0, t._self._c)("img", { staticClass: "lazy-img", style: t.style, attrs: { "data-src": t.lazySrc, alt: t.alt, title: t.title, height: "50", width: "50" }, on: { error: t.onError } });
                    },
                    [],
                    !1,
                    null,
                    null,
                    null
                );
            e.default = component.exports;
        },
        470: function (t, e, r) {
            t.exports = (function () {
                "use strict";
                var g = "undefined" != typeof document && document.documentMode,
                    t = {
                        rootMargin: "0px",
                        threshold: 0,
                        load: function (t) {
                            if ("picture" === t.nodeName.toLowerCase()) {
                                var e = t.querySelector("img"),
                                    r = !1;
                                null === e && ((e = document.createElement("img")), (r = !0)),
                                    g && t.getAttribute("data-iesrc") && (e.src = t.getAttribute("data-iesrc")),
                                    t.getAttribute("data-alt") && (e.alt = t.getAttribute("data-alt")),
                                    r && t.append(e);
                            }
                            if ("video" === t.nodeName.toLowerCase() && !t.getAttribute("data-src") && t.children) {
                                for (var a = t.children, n = void 0, i = 0; i <= a.length - 1; i++) (n = a[i].getAttribute("data-src")) && (a[i].src = n);
                                t.load();
                            }
                            t.getAttribute("data-poster") && (t.poster = t.getAttribute("data-poster")),
                                t.getAttribute("data-src") && (t.src = t.getAttribute("data-src")),
                                t.getAttribute("data-srcset") && t.setAttribute("srcset", t.getAttribute("data-srcset"));
                            var o = ",";
                            if ((t.getAttribute("data-background-delimiter") && (o = t.getAttribute("data-background-delimiter")), t.getAttribute("data-background-image")))
                                t.style.backgroundImage = "url('" + t.getAttribute("data-background-image").split(o).join("'),url('") + "')";
                            else if (t.getAttribute("data-background-image-set")) {
                                var l = t.getAttribute("data-background-image-set").split(o),
                                    u = l[0].substr(0, l[0].indexOf(" ")) || l[0];
                                (u = -1 === u.indexOf("url(") ? "url(" + u + ")" : u),
                                    1 === l.length
                                        ? (t.style.backgroundImage = u)
                                        : t.setAttribute("style", (t.getAttribute("style") || "") + "background-image: " + u + "; background-image: -webkit-image-set(" + l + "); background-image: image-set(" + l + ")");
                            }
                            t.getAttribute("data-toggle-class") && t.classList.toggle(t.getAttribute("data-toggle-class"));
                        },
                        loaded: function () {},
                    };
                function e(t) {
                    t.setAttribute("data-loaded", !0);
                }
                var r = function (t) {
                        return "true" === t.getAttribute("data-loaded");
                    },
                    n = function (t) {
                        var e = 1 < arguments.length && void 0 !== arguments[1] ? arguments[1] : document;
                        return t instanceof Element ? [t] : t instanceof NodeList ? t : e.querySelectorAll(t);
                    };
                return function () {
                    var o,
                        a,
                        l = 0 < arguments.length && void 0 !== arguments[0] ? arguments[0] : ".lozad",
                        c = 1 < arguments.length && void 0 !== arguments[1] ? arguments[1] : {},
                        d = Object.assign({}, t, c),
                        i = d.root,
                        h = d.rootMargin,
                        f = d.threshold,
                        u = d.load,
                        g = d.loaded,
                        s = void 0;
                    "undefined" != typeof window &&
                        window.IntersectionObserver &&
                        (s = new IntersectionObserver(
                            ((o = u),
                            (a = g),
                            function (t, n) {
                                t.forEach(function (t) {
                                    (0 < t.intersectionRatio || t.isIntersecting) && (n.unobserve(t.target), r(t.target) || (o(t.target), e(t.target), a(t.target)));
                                });
                            }),
                            { root: i, rootMargin: h, threshold: f }
                        ));
                    for (var v, m = n(l, i), b = 0; b < m.length; b++) (v = m[b]).getAttribute("data-placeholder-background") && (v.style.background = v.getAttribute("data-placeholder-background"));
                    return {
                        observe: function () {
                            for (var t = n(l, i), o = 0; o < t.length; o++) r(t[o]) || (s ? s.observe(t[o]) : (u(t[o]), e(t[o]), g(t[o])));
                        },
                        triggerLoad: function (t) {
                            r(t) || (u(t), e(t), g(t));
                        },
                        observer: s,
                    };
                };
            })();
        },
        475: function (t, e, r) {
            "use strict";
            r(125), r(50);
            e.a = {
                data: function () {
                    return { itemList: [], cbList: [] };
                },
                methods: {
                    checkAll: function (t) {
                        t.target.checked
                            ? (this.cbList = this.itemList.map(function (i) {
                                  return i.id;
                              }))
                            : (this.cbList = []);
                    },
                    deleteBulk: function () {
                        var t;
                        null !== (t = this.cbList) && void 0 !== t && t.length && this.$refs.listPage.deleteItem(this.cbList.join(","));
                    },
                },
            };
        },
        735: function (t, e, r) {
            var content = r(834);
            content.__esModule && (content = content.default), "string" == typeof content && (content = [[t.i, content, ""]]), content.locals && (t.exports = content.locals);
            (0, r(63).default)("56bf0f62", content, !0, { sourceMap: !1 });
        },
        833: function (t, e, r) {
            "use strict";
            r(735);
        },
        834: function (t, e, r) {
            var n = r(62)(function (i) {
                return i[1];
            });
            n.push([t.i, ".rating-review-wrapper table{min-width:800px}", ""]), (n.locals = {}), (t.exports = n);
        },
        898: function (t, e, r) {
            "use strict";
            r.r(e);
            r(37), r(44), r(32);
            var n = r(476),
                o = r(86),
                l = r(469),
                c = r(475),
                d = {
                    name: "rating-reviews",
                    middleware: ["common-middleware", "auth"],
                    data: function () {
                        return { 
                            ratingReviewObj: { 
                                user_id: { title: this.$t("fSale.user") }, 
                                created_at: { title: this.$t("category.date") }, 
                                rating: { title: this.$t("fSale.rating") }, 
                                product_id: { title: this.$t("fSale.product") } 
                            },
                            showReviewForm: false,
                            currentRating: 0,
                            hoverRating: 0,
                            products: [],
                            reviewForm: {
                                id: null,
                                name: '',
                                source: 'shop',
                                product_id: '',
                                review: '',
                                is_verified: false,
                                rating: 0
                            },
                            formErrors: {}
                        };
                    },
                    components: { LazyImage: l.default, ListPage: n.default },
                    mixins: [o.a, c.a],
                    computed: {},
                    methods: {
                        userName: function (t) {
                            var e, r;
                            return null != t && t.user ? (null == t || null === (e = t.user) || void 0 === e ? void 0 : e.name) : null != t && t.guest_user ? (null == t || null === (r = t.guest_user) || void 0 === r ? void 0 : r.name) : "";
                        },
                        customerName: function (t) {
                            var e, r;
                            return null != t && t.user ? (null == t || null === (e = t.user) || void 0 === e ? void 0 : e.name) : null != t && t.guest_user ? (null == t || null === (r = t.guest_user) || void 0 === r ? void 0 : r.name) : "";
                        },
                        toggleReviewForm() {
                            this.showReviewForm = !this.showReviewForm;
                           if (this.showReviewForm) {
                                this.resetForm();
                                this.loadProducts();
                                
                                // Scroll to form after it's rendered
                                this.$nextTick(() => {
                                    if (this.$refs.reviewFormContainer) {
                                        this.$refs.reviewFormContainer.scrollIntoView({
                                            behavior: 'smooth',
                                            block: 'start'
                                        });
                                    }
                                });
                            }
                        },
                        resetForm() {
                            this.reviewForm = {
                                id: null,
                                name: '',
                                source: 'shop',
                                product_id: '',
                                review: '',
                                is_verified: false,
                                rating: 0
                            };
                            this.currentRating = 0;
                            this.formErrors = {};
                        },
                        async loadProducts() {
                            try {
                                const response = await this.$axios.get('https://shop.fixmypc.ie/api/products/all-simple');
                                this.products = response.data.data || [];
                            } catch (error) {
                                console.error('Error loading products:', error);
                            }
                        },
                        setRating(rating) {
                            this.currentRating = rating;
                            this.reviewForm.rating = rating;
                            this.hoverRating = 0; // Clear hover state after selection
                            this.clearError('rating');
                        },
                        
                        setHoverRating(rating) {
                            if (this.currentRating === 0) {
                                this.hoverRating = rating;
                            }
                        },
                        
                        clearHoverRating() {
                            this.hoverRating = 0;
                        },
                        validateForm() {
                            let isValid = true;
                            this.formErrors = {};
                            
                            if (this.reviewForm.rating <= 0) {
                                this.formErrors.rating = 'Please select a rating';
                                isValid = false;
                            }
                            
                            if (!this.reviewForm.name.trim()) {
                                this.formErrors.name = 'Please enter customer name';
                                isValid = false;
                            }
                            
                            if (!this.reviewForm.source) {
                                this.formErrors.source = 'Please select a source';
                                isValid = false;
                            }
                            
                            if (!this.reviewForm.product_id) {
                                this.formErrors.product_id = 'Please select a product';
                                isValid = false;
                            }
                            
                            if (!this.reviewForm.review.trim()) {
                                this.formErrors.review = 'Please enter your review';
                                isValid = false;
                            }
                            
                            return isValid;
                        },
                        clearError(field) {
                            if (this.formErrors[field]) {
                                delete this.formErrors[field];
                            }
                        },
                         async submitReview() {
                            if (!this.validateForm()) return;
                            
                            try {
                                const formData = new FormData();
                                formData.append('name', this.reviewForm.name);
                                formData.append('source', this.reviewForm.source);
                                formData.append('product_id', this.reviewForm.product_id);
                                formData.append('review', this.reviewForm.review);
                                formData.append('is_verified', this.reviewForm.is_verified ? 1 : 0);
                                formData.append('rating', this.reviewForm.rating);
                                
                                if (this.reviewForm.id) {
                                    formData.append('review_id', this.reviewForm.id);
                                }

                                const url = this.reviewForm.id 
                                    ? 'https://shop.fixmypc.ie/api/v1/rating-review/update'
                                    : 'https://shop.fixmypc.ie/api/v1/rating-review/store';
                                
                                const response = await this.$axios.post(url, formData, {
                                    headers: {
                                        'Content-Type': 'multipart/form-data'
                                    }
                                });
                                
                                showAlert( 
                                    this.reviewForm.id 
                                        ? 'Review updated successfully!'
                                        : 'Review added successfully!'
                                    );
                                setTimeout(function(){
                                    location.reload();
                                },3000);
                                
                            } catch (error) {
                                console.error('Error submitting review:', error);
                            }
                        },
                        async editReview(review) {
                            this.showReviewForm = true;
                            this.reviewForm = {
                                id: review.id,
                                name: review.name,
                                source: review.source,
                                product_id: review.product_id,
                                review: review.review,
                                is_verified: review.is_verified,
                                rating: review.rating
                            };
                            this.currentRating = review.rating;
                            
                            if (this.products.length === 0) {
                                await this.loadProducts();
                            }

                             // Scroll to form after it's rendered
                            this.$nextTick(() => {
                                if (this.$refs.reviewFormContainer) {
                                    this.$refs.reviewFormContainer.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'start'
                                    });
                                }
                            });
                        }
                    },
                    mounted: function () {
                        // Add CSS styles dynamically
                        const style = document.createElement('style');
                        style.textContent = `
                            .page-actions {
                                float: right;
                                margin-top: 8px;
                                }
                            .review-form-container {
                                margin-bottom: 30px;
                                background: #fff;
                                padding: 20px;
                                border-radius: 8px;
                                border: 1px solid #eee;
                                margin-top: 15px;
                            }
                            
                            .form-row {
                                display: flex;
                                gap: 20px;
                                margin-bottom: 15px;
                            }
                            
                            .form-row .form-group {
                                flex: 1;
                            }
                            
                            .star-rating {
                                font-size: 24px;
                                margin: 5px 0;
                            }
                            
                            .star-rating span {
                                color: #ccc;
                                cursor: pointer;
                                margin-right: 5px;
                            }
                            
                            .star-rating span.active,
                            .star-rating span:hover {
                                color: gold;
                            }
                            
                            .form-group {
                                margin-bottom: 15px;
                            }
                            
                            .form-group label {
                                display: block;
                                margin-bottom: 8px;
                                font-weight: 500;
                            }
                            
                            .form-group input[type="text"],
                            .form-group select,
                            .form-group textarea {
                                width: 100%;
                                padding: 10px;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                                font-size: 14px;
                            }
                            
                            .form-group select {
                                height: 40px;
                            }
                            
                            .form-group textarea {
                                min-height: 100px;
                            }
                            
                            .checkbox-group {
                                display: flex;
                                align-items: center;
                                padding-top: 25px;
                            }
                            
                            .checkbox-group input {
                                margin-right: 10px;
                            }
                            
                            .form-actions {
                                display: flex;
                                justify-content: flex-end;
                                gap: 10px;
                                margin-top: 20px;
                            }
                            
                            .submit-btn {
                                background: #42b983;
                                color: white;
                                border: none;
                                border-radius: 4px;
                                cursor: pointer;
                                padding: 8px 15px;
                            }
                            
                            .cancel-btn {
                                background: #f5f5f5;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                                cursor: pointer;
                                padding: 8px 15px;
                            }
                            
                            .required {
                                color: red;
                            }
                            
                            .error-message {
                                font-size: 12px;
                                margin-top: 5px;
                                display: none;
                                color: red;
                            }
                            
                            .add-review-btn {
                                background: #42b983;
                                color: white;
                                border: none;
                                border-radius: 4px;
                                cursor: pointer;
                                padding: 8px 15px;
                                margin-left: 10px;
                            }
                        `;
                        document.head.appendChild(style);
                    },
                },
                h = (r(833), r(15)),
                component = Object(h.a)(
                    d,
                    function () {
                        var t = this,
                            e = t._self._c;
                        return e("div", [
                            e("div", { staticClass: "rating-review-page" }, [
                                // Add Review Button
                                e("div", { staticClass: "page-actions" }, [
                                    e("button", {
                                        staticClass: "add-review-btn primary-btn",
                                        on: {
                                            click: t.toggleReviewForm
                                        }
                                    }, [
                                        t._v(t._s(t.showReviewForm ? "Cancel" : "Add Review"))
                                    ])
                                ]),
                                
                                // List Page Component
                                e("list-page", {
                                    ref: "listPage",
                                    staticClass: "rating-review-wrapper",
                                    attrs: { 
                                        "list-api": "getRatingReviews", 
                                        "delete-api": "deleteRatingReview", 
                                        "route-name": "rating-reviews", 
                                        name: t.$t("fSale.rr"), 
                                        gate: "rating_review", 
                                        "add-button": 1, 
                                        "order-options": t.ratingReviewObj 
                                    },
                                    on: {
                                        "delete-bulk": t.deleteBulk,
                                        list: function (e) {
                                            t.itemList = e;
                                        },
                                    },
                                    scopedSlots: t._u([
                                        {
                                            key: "table",
                                            fn: function (r) {
                                                var n = r.list;
                                                return [
                                                    e("tr", { staticClass: "lite-bold" }, [
                                                        e("th", [e("input", { attrs: { type: "checkbox" }, on: { change: t.checkAll } })]),
                                                        t._v(" "),
                                                        e("th", [t._v(t._s(t.$t("fSale.customer")))]),
                                                        t._v(" "),
                                                        e("th", [t._v(t._s(t.$t("fSale.rating")))]),
                                                        t._v(" "),
                                                        e("th", [t._v(t._s(t.$t("fSale.review")))]),
                                                        t._v(" "),
                                                        e("th", { staticClass: "mn-w-130x" }, [t._v(t._s(t.$t("Source")))]),
                                                        t._v(" "),
                                                        e("th", [t._v(t._s(t.$t("fSale.product")))]),
                                                        t._v(" "),
                                                        e("th", [t._v(t._s(t.$t("category.created")))]),
                                                        t._v(" "),
                                                        e("th", [t._v(" ")]),
                                                    ]),
                                                    t._v(" "),
                                                    t._l(n, function (r, n) {
                                                        return e("tr", { key: n }, [
                                                            e("td", [
                                                                e("input", {
                                                                    directives: [{ name: "model", rawName: "v-model", value: t.cbList, expression: "cbList" }],
                                                                    attrs: { type: "checkbox" },
                                                                    domProps: { value: r.id, checked: Array.isArray(t.cbList) ? t._i(t.cbList, r.id) > -1 : t.cbList },
                                                                    on: {
                                                                        change: function (e) {
                                                                            var n = t.cbList,
                                                                                o = e.target,
                                                                                l = !!o.checked;
                                                                            if (Array.isArray(n)) {
                                                                                var c = r.id,
                                                                                    d = t._i(n, c);
                                                                                o.checked ? d < 0 && (t.cbList = n.concat([c])) : d > -1 && (t.cbList = n.slice(0, d).concat(n.slice(d + 1)));
                                                                            } else t.cbList = l;
                                                                        },
                                                                    },
                                                                }),
                                                            ]),
                                                            t._v(" "),
                                                            e("td", [e("span", [t._v(t._s(r.name))])]),
                                                            t._v(" "),
                                                            e("td", [e("span", [t._v(t._s(r.rating))])]),
                                                            t._v(" "),
                                                            e("td", [e("span", [t._v(t._s(r.review))])]),
                                                            t._v(" "),
                                                            e("td", [e("span", [t._v(t._s(r.source))])]),
                                                            t._v(" "),
                                                            e("td", [e("nuxt-link", { staticClass: "ellipsis mx-w-200x link", attrs: { to: "products/".concat(r.product.id) } }, [t._v("\n          " + t._s(r.product.title) + "\n        ")])], 1),
                                                            t._v(" "),
                                                            e("td", [t._v(t._s(r.created))]),
                                                            t._v(" "),
                                                            e("td", [
                                                                t.$can("rating_review", "delete")
                                                                    ? e(
                                                                          "button",
                                                                          {
                                                                              staticClass: "delete-btn lite-btn",
                                                                              on: {
                                                                                  click: function (e) {
                                                                                      return e.preventDefault(), t.$refs.listPage.deleteItem(r.id);
                                                                                  },
                                                                              },
                                                                          },
                                                                          [t._v(t._s(t.$t("category.delete")))]
                                                                      )
                                                                    : t._e(),
                                                                    t.$can("rating_review", "delete")
                                                                    ? e(
                                                                          "button",
                                                                          {
                                                                              staticClass: "edit_review lite-btn",
                                                                              on: {
                                                                                  click: function(e) {
                                                                                      e.preventDefault();
                                                                                      t.editReview(r);
                                                                                  }
                                                                              },
                                                                          },
                                                                          [t._v(t._s(t.$t("category.edit")))]
                                                                      )
                                                                    : t._e(),
                                                            ]),
                                                        ]);
                                                    }),
                                                ];
                                            },
                                        },
                                    ]),
                                }),
                                
                                // Review Form
                                t.showReviewForm ? e("div", { staticClass: "review-form-container" , ref: "reviewFormContainer"}, [
                                    e("div", { staticClass: "review-form-inline" }, [
                                        e("h3", [t._v(t._s(t.reviewForm.id ? "Edit Review" : "Add New Review"))]),
                                        t._v(" "),
                                        e("form", {
                                            staticClass: "review-form",
                                            on: {
                                                submit: function(e) {
                                                    e.preventDefault();
                                                    t.submitReview();
                                                }
                                            }
                                        }, [
                                            e("input", {
                                                directives: [{
                                                    name: "model",
                                                    rawName: "v-model",
                                                    value: t.reviewForm.id,
                                                    expression: "reviewForm.id"
                                                }],
                                                attrs: { type: "hidden", name: "review_id", id: "review_id" },
                                                domProps: { value: t.reviewForm.id },
                                                on: {
                                                    input: function(e) {
                                                        e.target.composing || (t.$set(t.reviewForm, "id", e.target.value));
                                                    }
                                                }
                                            }),
                                            e("div", { staticClass: "star-rating" }, [
                                                e("span", {
                                                    class: { active: 1 <= this.currentRating || (1 <= this.hoverRating && this.currentRating === 0) },
                                                    on: {
                                                        click: function(e) { t.setRating(1); },
                                                        mouseover: function(e) { t.setHoverRating(1); },
                                                        mouseout: t.clearHoverRating
                                                    }
                                                }, [t._v("★")]),
                                                t._v(" "),
                                                e("span", {
                                                    class: { active: 2 <= this.currentRating || (2 <= this.hoverRating && this.currentRating === 0) },
                                                    on: {
                                                        click: function(e) { t.setRating(2); },
                                                        mouseover: function(e) { t.setHoverRating(2); },
                                                        mouseout: t.clearHoverRating
                                                    }
                                                }, [t._v("★")]),
                                                t._v(" "),
                                                e("span", {
                                                    class: { active: 3 <= this.currentRating || (3 <= this.hoverRating && this.currentRating === 0) },
                                                    on: {
                                                        click: function(e) { t.setRating(3); },
                                                        mouseover: function(e) { t.setHoverRating(3); },
                                                        mouseout: t.clearHoverRating
                                                    }
                                                }, [t._v("★")]),
                                                t._v(" "),
                                                e("span", {
                                                    class: { active: 4 <= this.currentRating || (4 <= this.hoverRating && this.currentRating === 0) },
                                                    on: {
                                                        click: function(e) { t.setRating(4); },
                                                        mouseover: function(e) { t.setHoverRating(4); },
                                                        mouseout: t.clearHoverRating
                                                    }
                                                }, [t._v("★")]),
                                                t._v(" "),
                                                e("span", {
                                                    class: { active: 5 <= this.currentRating || (5 <= this.hoverRating && this.currentRating === 0) },
                                                    on: {
                                                        click: function(e) { t.setRating(5); },
                                                        mouseover: function(e) { t.setHoverRating(5); },
                                                        mouseout: t.clearHoverRating
                                                    }
                                                }, [t._v("★")])
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-row" }, [
                                                e("div", { staticClass: "form-group" }, [
                                                    e("label", [t._v("Source "), e("span", { staticClass: "required" }, [t._v("*")])]),
                                                    t._v(" "),
                                                    e("select", {
                                                        directives: [{
                                                            name: "model",
                                                            rawName: "v-model",
                                                            value: t.reviewForm.source,
                                                            expression: "reviewForm.source"
                                                        }],
                                                        attrs: { name: "source" },
                                                        on: {
                                                            change: function(e) {
                                                                var r = Array.prototype.filter.call(e.target.options, function(t) {
                                                                    return t.selected;
                                                                }).map(function(t) {
                                                                    return "_value" in t ? t._value : t.value;
                                                                });
                                                                t.$set(t.reviewForm, "source", e.target.multiple ? r : r[0]);
                                                                t.clearError('source');
                                                            }
                                                        }
                                                    }, [
                                                        e("option", { attrs: { value: "" } }, [t._v("Select Source")]),
                                                        t._v(" "),
                                                        e("option", { attrs: { value: "google" } }, [t._v("Google")]),
                                                        t._v(" "),
                                                        e("option", { attrs: { value: "facebook" } }, [t._v("Facebook")]),
                                                        t._v(" "),
                                                        e("option", { attrs: { value: "shop" } }, [t._v("Shop")])
                                                    ]),
                                                    t._v(" "),
                                                    t.formErrors.source ? e("div", { staticClass: "error-message" }, [t._v(t._s(t.formErrors.source))]) : t._e()
                                                ])
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-row" }, [
                                                e("div", { staticClass: "form-group" }, [
                                                    e("label", [t._v("Customer Name "), e("span", { staticClass: "required" }, [t._v("*")])]),
                                                    t._v(" "),
                                                    e("input", {
                                                        directives: [{
                                                            name: "model",
                                                            rawName: "v-model",
                                                            value: t.reviewForm.name,
                                                            expression: "reviewForm.name"
                                                        }],
                                                        attrs: { type: "text", name: "name" },
                                                        domProps: { value: t.reviewForm.name },
                                                        on: {
                                                            input: function(e) {
                                                                e.target.composing || (t.$set(t.reviewForm, "name", e.target.value));
                                                                t.clearError('name');
                                                            }
                                                        }
                                                    }),
                                                    t._v(" "),
                                                    t.formErrors.name ? e("div", { staticClass: "error-message" }, [t._v(t._s(t.formErrors.name))]) : t._e()
                                                ])
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-group" }, [
                                                e("label", [t._v("Product "), e("span", { staticClass: "required" }, [t._v("*")])]),
                                                t._v(" "),
                                                e("select", {
                                                    directives: [{
                                                        name: "model",
                                                        rawName: "v-model",
                                                        value: t.reviewForm.product_id,
                                                        expression: "reviewForm.product_id"
                                                    }],
                                                    attrs: { name: "product_id" },
                                                    on: {
                                                        change: function(e) {
                                                            var r = Array.prototype.filter.call(e.target.options, function(t) {
                                                                return t.selected;
                                                            }).map(function(t) {
                                                                return "_value" in t ? t._value : t.value;
                                                            });
                                                            t.$set(t.reviewForm, "product_id", e.target.multiple ? r : r[0]);
                                                            t.clearError('product_id');
                                                        }
                                                    }
                                                }, [
                                                    e("option", { attrs: { value: "" } }, [t._v("Select Product")]),
                                                    t._v(" "),
                                                    t._l(t.products, function(r) {
                                                        return e("option", { key: r.id, attrs: { value: r.id } }, [t._v(t._s(r.title))])
                                                    })
                                                ], 2),
                                                t._v(" "),
                                                t.formErrors.product_id ? e("div", { staticClass: "error-message" }, [t._v(t._s(t.formErrors.product_id))]) : t._e()
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-group" }, [
                                                e("label", [t._v("Review "), e("span", { staticClass: "required" }, [t._v("*")])]),
                                                t._v(" "),
                                                e("textarea", {
                                                    directives: [{
                                                        name: "model",
                                                        rawName: "v-model",
                                                        value: t.reviewForm.review,
                                                        expression: "reviewForm.review"
                                                    }],
                                                    attrs: { name: "review", rows: "4" },
                                                    domProps: { value: t.reviewForm.review },
                                                    on: {
                                                        input: function(e) {
                                                            e.target.composing || (t.$set(t.reviewForm, "review", e.target.value));
                                                            t.clearError('review');
                                                        }
                                                    }
                                                }),
                                                t._v(" "),
                                                t.formErrors.review ? e("div", { staticClass: "error-message" }, [t._v(t._s(t.formErrors.review))]) : t._e()
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-row" }, [
                                                e("div", { staticClass: "checkbox-group" }, [
                                                    e("input", {
                                                        directives: [{
                                                            name: "model",
                                                            rawName: "v-model",
                                                            value: t.reviewForm.is_verified,
                                                            expression: "reviewForm.is_verified"
                                                        }],
                                                        attrs: { type: "checkbox", id: "is_verified", name: "is_verified" },
                                                        domProps: {
                                                            checked: Array.isArray(t.reviewForm.is_verified) ? t._i(t.reviewForm.is_verified, null) > -1 : t.reviewForm.is_verified
                                                        },
                                                        on: {
                                                            change: function(e) {
                                                                var r = t.reviewForm.is_verified,
                                                                    n = e.target,
                                                                    o = !!n.checked;
                                                                if (Array.isArray(r)) {
                                                                    var l = null,
                                                                        c = t._i(r, l);
                                                                    n.checked ? c < 0 && (t.$set(t.reviewForm, "is_verified", r.concat([l]))) : c > -1 && (t.$set(t.reviewForm, "is_verified", r.slice(0, c).concat(r.slice(c + 1))));
                                                                } else t.$set(t.reviewForm, "is_verified", o);
                                                            }
                                                        }
                                                    }),
                                                    t._v(" "),
                                                    e("label", { attrs: { for: "is_verified" } }, [t._v("Verified Purchase")])
                                                ])
                                            ]),
                                            t._v(" "),
                                            e("div", { staticClass: "form-actions" }, [
                                                e("button", {
                                                    staticClass: "cancel-btn primary-btn",
                                                    attrs: { type: "button" },
                                                    on: {
                                                        click: function(e) {
                                                            t.showReviewForm = false;
                                                            t.resetForm();
                                                        }
                                                    }
                                                }, [t._v("Cancel")]),
                                                t._v(" "),
                                                e("button", {
                                                    staticClass: "submit-btn primary-btn",
                                                    attrs: { type: "submit" }
                                                }, [t._v(t._s(t.reviewForm.id ? "Update Review" : "Submit Review"))])
                                            ])
                                        ])
                                    ])
                                ]) : t._e(),
                                
                            ])
                        ]);
                    },
                    [],
                    !1,
                    null,
                    null,
                    null
                );
            e.default = component.exports;
        },
    },
]);