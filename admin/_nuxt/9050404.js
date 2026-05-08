(window.webpackJsonp = window.webpackJsonp || []).push([
    [167],
    {
        475: function (t, e, c) {
            "use strict";
            c(125), c(50);
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
       885: function (t, e, c) {
            "use strict";
            c.r(e);
            c(37), c(44);
            var l = c(476),
                n = c(86),
                r = c(475),
                o = {
                    name: "upsell",
                    middleware: ["common-middleware", "auth"],
                    data: function () {
                        return { 
                            orderOptions: { 
                                created_at: { title: this.$t("category.date") }, 
                                title: { title: this.$t("index.title") }, 
                                status: { title: this.$t("category.status") } 
                            }
                        };
                    },
                    mixins: [n.a, r.a],
                    components: { ListPage: l.default },
                    methods: {
                       
                    }
                },
                d = c(15),
                component = Object(d.a)(
                    o,
                    function () {
                        var t = this,
                            e = t._self._c;

                        var style = document.createElement('style');
                        style.type = 'text/css';
                        style.innerHTML = `
                           .add_upsell {
                            position: relative;
                            top: 10px;
                            float: right;
                            }
                        `;
                        document.head.appendChild(style);

                        return e("div", [
                            // Add button above the table
                            e("div", { staticClass: "page-actions" }, [
                                e("button", {
                                    staticClass: "add-btn primary-btn add_upsell",
                                    on: {
                                        click: function() {
                                            t.$router.push('/upsell/create');
                                        }
                                    }
                                }, [t._v(t._s(t.$t("Add Upsell")))])
                            ]),
                            t._v(" "),
                            e("list-page", {
                                ref: "listPage",
                                attrs: { 
                                    "list-api": "getUpsells", 
                                    "delete-api": "deleteUpsell", 
                                    "route-name": "upsell", 
                                    name: t.$t("upsells"), 
                                    "order-options": t.orderOptions
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
                                        fn: function (c) {
                                            var l = c.list;
                                            return [
                                                e("tr", { staticClass: "lite-bold" }, [
                                                    e("th", [e("input", { attrs: { type: "checkbox" }, on: { change: t.checkAll } })]),
                                                    t._v(" "),
                                                    e("th", [t._v(t._s(t.$t("index.title")))]),
                                                    t._v(" "),
                                                    e("th", [t._v(t._s(t.$t("category.status")))]),
                                                   /* t._v(" "),
                                                    e("th", [t._v(t._s(t.$t("category.created")))]), */
                                                    t._v(" "),
                                                    e("th", [t._v(" ")])
                                                ]),
                                                t._v(" "),
                                                t._l(l, function (c, l) {
                                                    return e("tr", { key: l }, [
                                                    e("td", [
                                                        e("input", {
                                                            directives: [{ name: "model", rawName: "v-model", value: t.cbList, expression: "cbList" }],
                                                            attrs: { type: "checkbox" },
                                                            domProps: { value: c.id, checked: Array.isArray(t.cbList) ? t._i(t.cbList, c.id) > -1 : t.cbList },
                                                            on: {
                                                                change: function (e) {
                                                                    var l = t.cbList,
                                                                        n = e.target,
                                                                        r = !!n.checked;
                                                                    if (Array.isArray(l)) {
                                                                        var o = c.id,
                                                                            d = t._i(l, o);
                                                                        n.checked ? d < 0 && (t.cbList = l.concat([o])) : d > -1 && (t.cbList = l.slice(0, d).concat(l.slice(d + 1)));
                                                                    } else t.cbList = r;
                                                                },
                                                            },
                                                        }),
                                                    ]),
                                                    t._v(" "),
                                                    e("td", [e("nuxt-link", { staticClass: "link", attrs: { to: "/upsell/".concat(c.id) } }, [e("h5", { staticClass: "mx-w-300x" }, [t._v(t._s(c.title))])])], 1),
                                                    t._v(" "),
                                                    e("td", { staticClass: "status", class: { active: 1 == c.status } }, [e("span", [t._v(t._s(t.getStatus(c.status)))])]),
                                                   /* t._v(" "),
                                                    e("td", [t._v(t._s(c.created))]), */
                                                    t._v(" "),
                                                    e("td", [
                                                        t.$can("flash_sale", "edit")
                                                            ? e(
                                                                  "button",
                                                                  {
                                                                      staticClass: "lite-btn",
                                                                      on: {
                                                                          click: function (e) {
                                                                              return e.preventDefault(), t.$refs.listPage.editItem(c.id);
                                                                          },
                                                                      },
                                                                  },
                                                                  [t._v(t._s(t.$t("category.edit")))]
                                                              )
                                                            : t._e(),
                                                        t._v(" "),
                                                        t.$can("flash_sale", "delete")
                                                            ? e(
                                                                  "button",
                                                                  {
                                                                      staticClass: "delete-btn lite-btn",
                                                                      on: {
                                                                          click: function (e) {
                                                                              return e.preventDefault(), t.$refs.listPage.deleteItem(c.id);
                                                                          },
                                                                      },
                                                                  },
                                                                  [t._v(t._s(t.$t("category.delete")))]
                                                              )
                                                            : t._e(),
                                                    ]),
                                                ]);
                                                }),
                                            ];
                                        },
                                    },
                                ]),
                            })
                        ]);
                    },
                    [],
                    !1,
                    null,
                    "286e19f3",
                    null
                );
            e.default = component.exports;
        },
    },
]);
